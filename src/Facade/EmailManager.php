<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Facade;

use ExcelleInsights\Email\Config\EmailConfig;
use ExcelleInsights\Email\Contracts\ApprovalAuthorizerInterface;
use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Controller\WebhookController;
use ExcelleInsights\Email\Entities\BulkSendResult;
use ExcelleInsights\Email\Entities\SendResult;
use ExcelleInsights\Email\Providers\BuiltInProviders;
use ExcelleInsights\Email\Providers\ProviderRegistry;
use ExcelleInsights\Email\Repositories\CampaignRepository;
use ExcelleInsights\Email\Repositories\EventRepository;
use ExcelleInsights\Email\Repositories\QueueRepository;
use ExcelleInsights\Email\Repositories\SenderRepository;
use ExcelleInsights\Email\Repositories\StatusChangeRepository;
use ExcelleInsights\Email\Repositories\SuppressionRepository;
use ExcelleInsights\Email\Repositories\TemplateRepository;
use ExcelleInsights\Email\Repositories\TemplateVersionRepository;
use ExcelleInsights\Email\Repositories\WebhookLogRepository;
use ExcelleInsights\Email\Services\CampaignService;
use ExcelleInsights\Email\Services\EventService;
use ExcelleInsights\Email\Services\MessageStatusService;
use ExcelleInsights\Email\Services\ProviderResolver;
use ExcelleInsights\Email\Services\SendService;
use ExcelleInsights\Email\Services\StatusTransitionPolicy;
use ExcelleInsights\Email\Services\SuppressionService;
use ExcelleInsights\Email\Services\TemplateRenderer;
use ExcelleInsights\Email\Services\TemplateService;
use ExcelleInsights\Email\Services\WebhookService;
use ExcelleInsights\Email\Services\WorkerService;
use ExcelleInsights\Email\Support\CryptoInterface;
use ExcelleInsights\Email\Support\EventTypeNormalizer;
use ExcelleInsights\Email\Support\HttpRequestLogger;
use ExcelleInsights\Email\Support\OpenSslCrypto;
use ExcelleInsights\Email\Validation\EmailAddressValidator;
use ExcelleInsights\Email\Validation\MessageValidator;
use ExcelleInsights\Email\Validation\TemplateValidator;
use PDO;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

class EmailManager
{
    private readonly EmailConfig $config;
    private readonly CryptoInterface $crypto;
    private readonly SenderRepository $senderRepository;
    private readonly ProviderRegistry $providerRegistry;
    private readonly ProviderResolver $providerResolver;
    private readonly StatusChangeRepository $statusChangeRepository;
    private readonly WebhookLogRepository $webhookLogRepository;
    private readonly HttpRequestLogger $httpRequestLogger;
    private readonly QueueRepository $queueRepository;
    private readonly CampaignRepository $campaignRepository;
    private readonly EventRepository $eventRepository;
    private readonly TemplateRepository $templateRepository;
    private readonly TemplateVersionRepository $templateVersionRepository;
    private readonly SuppressionRepository $suppressionRepository;
    private readonly SuppressionService $suppressionService;
    private readonly TemplateRenderer $templateRenderer;
    private readonly TemplateService $templateService;
    private readonly MessageStatusService $messageStatusService;
    private readonly SendService $sendService;
    private readonly WorkerService $workerService;
    private readonly CampaignService $campaignService;
    private readonly EventService $eventService;
    private readonly WebhookService $webhookService;
    private readonly WebhookController $webhookController;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        private readonly PDO $pdo,
        private readonly HttpClientInterface $httpClient,
        private readonly PsrLoggerInterface $logger,
        ?ApprovalAuthorizerInterface $authorizer = null,
    ) {
        $this->config = new EmailConfig($config);
        $this->crypto = new OpenSslCrypto($this->config->encryptionKey());
        $prefix = $this->config->tablePrefix();

        $this->senderRepository = new SenderRepository($pdo, $this->crypto, $prefix);
        $this->providerResolver = new ProviderResolver($this->senderRepository, $this->config);
        $this->providerRegistry = new ProviderRegistry();
        $this->statusChangeRepository = new StatusChangeRepository($pdo, $prefix);
        $this->webhookLogRepository = new WebhookLogRepository($pdo, $prefix);
        $this->httpRequestLogger = new HttpRequestLogger($pdo, 'http_request_logs');

        $this->queueRepository = new QueueRepository($pdo, $prefix);
        $this->campaignRepository = new CampaignRepository($pdo, $prefix);
        $this->eventRepository = new EventRepository($pdo, $prefix);
        $this->templateRepository = new TemplateRepository($pdo, $prefix);
        $this->templateVersionRepository = new TemplateVersionRepository($pdo, $prefix);
        $this->suppressionRepository = new SuppressionRepository($pdo, $prefix);
        $this->suppressionService = new SuppressionService($this->suppressionRepository);
        $this->templateRenderer = new TemplateRenderer(
            $this->config->escapeVariables(),
            $this->config->globals(),
        );
        $templateValidator = new TemplateValidator();
        $this->templateService = new TemplateService(
            $this->templateRepository,
            $this->templateVersionRepository,
            $this->templateRenderer,
            $templateValidator,
            $this->config,
        );

        $transitionPolicy = new StatusTransitionPolicy();
        $this->messageStatusService = new MessageStatusService(
            $this->queueRepository,
            $this->campaignRepository,
            $this->statusChangeRepository,
            $transitionPolicy,
            $authorizer,
        );
        $this->sendService = new SendService(
            $this->config,
            $this->providerResolver,
            $this->senderRepository,
            $this->queueRepository,
            $this->campaignRepository,
            $this->suppressionService,
            $this->templateService,
            $this->templateRenderer,
            new EmailAddressValidator(),
            new MessageValidator(),
            $this->providerRegistry,
            $this->statusChangeRepository,
            $logger,
        );
        $this->workerService = new WorkerService(
            $this->queueRepository,
            $this->campaignRepository,
            $this->statusChangeRepository,
            $this->sendService,
            $logger,
        );

        $eventTypes = new EventTypeNormalizer();
        $this->eventService = new EventService(
            $this->eventRepository,
            $this->queueRepository,
            $this->campaignRepository,
            $this->suppressionService,
            $eventTypes,
        );
        $this->campaignService = new CampaignService(
            $this->campaignRepository,
            $this->queueRepository,
            $this->eventRepository,
            $this->sendService,
            $this->messageStatusService,
        );
        $this->webhookService = new WebhookService(
            $this->providerRegistry,
            $this->config,
            $this->webhookLogRepository,
            $this->eventService,
            $logger,
        );
        $this->webhookController = new WebhookController($this->webhookService);
    }

    public function registerProvider(EmailProviderInterface $provider): void
    {
        $this->providerRegistry->register($provider);
    }

    /**
     * Register the bundled SMTP, Mailgun, Mandrill and log adapters.
     */
    public function registerBuiltInProviders(): void
    {
        foreach (BuiltInProviders::all($this) as $provider) {
            $this->registerProvider($provider);
        }
    }

    public function provider(string $key): EmailProviderInterface
    {
        return $this->providerRegistry->get($key);
    }

    /**
     * @return array<int, string>
     */
    public function listProviders(): array
    {
        return $this->providerRegistry->keys();
    }

    public function setDefaultSender(string $providerKey, ?int $tenantId = null): void
    {
        $this->senderRepository->setDefaultSender($providerKey, $tenantId);
    }

    public function getDefaultSender(?int $tenantId = null): ?string
    {
        return $this->senderRepository->getDefaultSender($tenantId)
            ?? $this->config->defaultSender()
            ?? $this->config->defaultProvider();
    }

    public function resolveProvider(?string $provider = null, ?int $tenantId = null): string
    {
        return $this->providerResolver->resolve($provider, $tenantId);
    }

    public function senders(): SenderRepository
    {
        return $this->senderRepository;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSenderCredentials(string $provider, ?int $tenantId = null): ?array
    {
        return $this->senderRepository->getCredentials($provider, $tenantId);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function setSenderCredentials(
        string $provider,
        ?int $tenantId,
        array $credentials,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $replyTo = null,
        ?string $name = null,
    ): void {
        $this->senderRepository->saveCredentials(
            $provider,
            $tenantId,
            $credentials,
            $fromEmail,
            $fromName,
            $replyTo,
            $name,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(string $to, string $subject, string $html, array $options = []): SendResult
    {
        return $this->sendService->send($to, $subject, $html, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendNow(string $to, string $subject, string $html, array $options = []): SendResult
    {
        return $this->sendService->sendNow($to, $subject, $html, $options);
    }

    /**
     * @param array<int, array<string, mixed>> $recipients
     * @param array<string, mixed> $options
     */
    public function sendBulk(array $recipients, string $subject, string $html, array $options = []): BulkSendResult
    {
        return $this->sendService->sendBulk($recipients, $subject, $html, $options);
    }

    public function templates(): TemplateService
    {
        return $this->templateService;
    }

    public function status(): MessageStatusService
    {
        return $this->messageStatusService;
    }

    public function worker(): WorkerService
    {
        return $this->workerService;
    }

    public function suppression(): SuppressionService
    {
        return $this->suppressionService;
    }

    public function queue(): QueueRepository
    {
        return $this->queueRepository;
    }

    public function campaigns(): CampaignService
    {
        return $this->campaignService;
    }

    public function campaignRepository(): CampaignRepository
    {
        return $this->campaignRepository;
    }

    public function events(): EventRepository
    {
        return $this->eventRepository;
    }

    public function eventService(): EventService
    {
        return $this->eventService;
    }

    public function webhooks(): WebhookService
    {
        return $this->webhookService;
    }

    public function webhookController(): WebhookController
    {
        return $this->webhookController;
    }

    public function statusChanges(): StatusChangeRepository
    {
        return $this->statusChangeRepository;
    }

    public function webhookLogs(): WebhookLogRepository
    {
        return $this->webhookLogRepository;
    }

    public function httpRequestLogger(): HttpRequestLogger
    {
        return $this->httpRequestLogger;
    }

    public function getConfig(): EmailConfig
    {
        return $this->config;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }

    public function getLogger(): PsrLoggerInterface
    {
        return $this->logger;
    }
}
