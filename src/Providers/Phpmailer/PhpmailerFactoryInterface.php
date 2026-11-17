<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Phpmailer;

use PHPMailer\PHPMailer\PHPMailer;

interface PhpmailerFactoryInterface
{
    public function create(): PHPMailer;
}
