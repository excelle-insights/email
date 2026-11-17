<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Support;

use ExcelleInsights\Email\Providers\Phpmailer\PhpmailerFactoryInterface;
use PHPMailer\PHPMailer\PHPMailer;

final class StaticPhpmailerFactory implements PhpmailerFactoryInterface
{
    public function __construct(private readonly PHPMailer $mail)
    {
    }

    public function create(): PHPMailer
    {
        return $this->mail;
    }
}
