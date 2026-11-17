<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Phpmailer;

use PHPMailer\PHPMailer\PHPMailer;

final class DefaultPhpmailerFactory implements PhpmailerFactoryInterface
{
    public function create(): PHPMailer
    {
        return new PHPMailer(true);
    }
}
