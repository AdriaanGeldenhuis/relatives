<?php
declare(strict_types=1);

/**
 * ============================================
 * EMAIL SERVICE - SENDGRID INTEGRATION
 * Professional transactional email delivery
 * ============================================
 */

// Only load SendGrid if installed
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class EmailService {
    private string $fromEmail = 'noreply@relatives.co.za';
    private string $fromName = 'Relatives Family Hub';
    private string $baseUrl;
    private ?string $sendgridApiKey = null;
    private bool $useSendGrid = false;
    
    public function __construct() {
        $this->baseUrl = $this->getBaseUrl();
        
        // Check for SendGrid API key
        $this->sendgridApiKey = $_ENV['SENDGRID_API_KEY'] ?? null;
        $this->useSendGrid = !empty($this->sendgridApiKey) && class_exists('\SendGrid');
        
        if (!$this->useSendGrid) {
            error_log("⚠️ SendGrid not configured. Emails will be logged instead of sent.");
        }
    }
    
    private function getBaseUrl(): string {
        return $_ENV['APP_URL'] ?? 'https://www.relatives.co.za';
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordReset(string $email, string $userName, string $token): bool {
        $resetLink = $this->baseUrl . '/reset-password.php?token=' . urlencode($token);
        $subject = '🔐 Reset Your Password - Relatives';
        
        $htmlBody = $this->getPasswordResetTemplate($userName, $resetLink, $token);
        $textBody = $this->getPasswordResetTextTemplate($userName, $resetLink, $token);
        
        return $this->send($email, $userName, $subject, $htmlBody, $textBody);
    }
    
    /**
     * Send welcome email for new family creator
     */
    public function sendWelcomeFamily(string $email, string $userName, string $familyName, string $inviteCode): bool {
        $subject = '🎉 Welcome to Relatives - Your Family Hub is Ready!';
        
        $htmlBody = $this->getWelcomeFamilyTemplate($userName, $familyName, $inviteCode);
        $textBody = $this->getWelcomeFamilyTextTemplate($userName, $familyName, $inviteCode);
        
        return $this->send($email, $userName, $subject, $htmlBody, $textBody);
    }
    
    /**
     * Send welcome email for new family member
     */
    public function sendWelcomeMember(string $email, string $userName, string $familyName): bool {
        $subject = '👋 Welcome to ' . $familyName . ' - Relatives';
        
        $htmlBody = $this->getWelcomeMemberTemplate($userName, $familyName);
        $textBody = $this->getWelcomeMemberTextTemplate($userName, $familyName);
        
        return $this->send($email, $userName, $subject, $htmlBody, $textBody);
    }
    
    /**
     * Core send method - Uses SendGrid or fallback
     */
    private function send(string $to, string $toName, string $subject, string $htmlBody, string $textBody): bool {
        if ($this->useSendGrid) {
            return $this->sendViaSendGrid($to, $toName, $subject, $htmlBody, $textBody);
        } else {
            return $this->sendViaFallback($to, $toName, $subject, $htmlBody, $textBody);
        }
    }
    
    /**
     * Send via SendGrid API
     */
    private function sendViaSendGrid(string $to, string $toName, string $subject, string $htmlBody, string $textBody): bool {
        try {
            $email = new \SendGrid\Mail\Mail();
            
            // From
            $email->setFrom($this->fromEmail, $this->fromName);
            
            // To
            $email->addTo($to, $toName);
            
            // Subject
            $email->setSubject($subject);
            
            // Content
            $email->addContent("text/plain", $textBody);
            $email->addContent("text/html", $htmlBody);
            
            // Categories for tracking
            $email->addCategory("relatives-app");
            
            // Send
            $sendgrid = new \SendGrid($this->sendgridApiKey);
            $response = $sendgrid->send($email);
            
            $statusCode = $response->statusCode();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                error_log("✅ Email sent successfully via SendGrid to {$to} (Status: {$statusCode})");
                return true;
            } else {
                error_log("❌ SendGrid error (Status: {$statusCode}): " . $response->body());
                return false;
            }
            
        } catch (Exception $e) {
            error_log("❌ SendGrid exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Fallback method - Log email content
     */
    private function sendViaFallback(string $to, string $toName, string $subject, string $htmlBody, string $textBody): bool {
        // In development/testing - log the email
        $logEntry = sprintf(
            "\n========================================\n" .
            "📧 EMAIL LOG - %s\n" .
            "========================================\n" .
            "To: %s <%s>\n" .
            "From: %s <%s>\n" .
            "Subject: %s\n" .
            "----------------------------------------\n" .
            "TEXT CONTENT:\n%s\n" .
            "----------------------------------------\n" .
            "HTML LENGTH: %d characters\n" .
            "========================================\n",
            date('Y-m-d H:i:s'),
            $toName,
            $to,
            $this->fromName,
            $this->fromEmail,
            $subject,
            $textBody,
            strlen($htmlBody)
        );
        
        error_log($logEntry);
        
        // Also try PHP mail() as final fallback
        try {
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
                'Reply-To: ' . $this->fromEmail,
                'X-Mailer: PHP/' . phpversion()
            ];
            
            $result = mail($to, $subject, $htmlBody, implode("\r\n", $headers));
            
            if ($result) {
                error_log("✅ Email sent via PHP mail() to {$to}");
            } else {
                error_log("⚠️ PHP mail() failed for {$to}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("❌ Mail error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Password Reset Email Template (HTML)
     */
    private function getPasswordResetTemplate(string $userName, string $resetLink, string $token): string {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
        .container { max-width: 600px; margin: 40px auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 48px 40px; text-align: center; }
        .logo { font-size: 64px; margin-bottom: 16px; }
        .header h1 { color: white; font-size: 32px; margin: 0; font-weight: 900; }
        .content { padding: 48px 40px; }
        .icon { font-size: 72px; text-align: center; margin-bottom: 24px; }
        .title { color: #1a202c; font-size: 28px; margin: 0 0 16px 0; font-weight: 800; text-align: center; }
        .text { color: #4a5568; font-size: 16px; line-height: 1.6; margin: 0 0 32px 0; text-align: center; }
        .button-container { text-align: center; margin: 32px 0; }
        .button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 18px 48px; border-radius: 12px; font-weight: 800; font-size: 16px; }
        .warning { background: #fff5f5; border-left: 4px solid #f56565; padding: 16px; border-radius: 8px; margin: 24px 0; }
        .warning-text { color: #742a2a; font-size: 14px; margin: 0; }
        .footer { background: #f7fafc; padding: 32px 40px; text-align: center; border-top: 1px solid #e2e8f0; }
        .footer-text { color: #718096; font-size: 13px; margin: 0; }
        .link { color: #667eea; font-size: 12px; word-break: break-all; background: #f7fafc; padding: 12px; border-radius: 8px; margin: 16px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🏠</div>
            <h1>Relatives</h1>
        </div>
        
        <div class="content">
            <div class="icon">🔐</div>
            <h2 class="title">Reset Your Password</h2>
            <p class="text">Hi <strong>{$userName}</strong>, we received a request to reset your password.</p>
            
            <div class="button-container">
                <a href="{$resetLink}" class="button">Reset Password</a>
            </div>
            
            <p class="text" style="color: #718096; font-size: 14px;">This link will expire in 1 hour for security.</p>
            
            <div class="warning">
                <p class="warning-text">
                    <strong>⚠️ Security Notice:</strong> If you didn't request this password reset, please ignore this email.
                </p>
            </div>
            
            <div style="border-top: 2px solid #e2e8f0; padding-top: 24px; margin-top: 24px;">
                <p style="color: #718096; font-size: 13px; margin: 0 0 12px 0;">
                    If the button doesn't work, copy this link:
                </p>
                <div class="link">{$resetLink}</div>
            </div>
        </div>
        
        <div class="footer">
            <p class="footer-text">© 2024 Relatives - Your Family Hub</p>
            <p class="footer-text" style="color: #a0aec0; font-size: 12px; margin-top: 8px;">
                This is an automated email. Please do not reply.
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Password Reset Email Template (Plain Text)
     */
    private function getPasswordResetTextTemplate(string $userName, string $resetLink, string $token): string {
        return <<<TEXT
🏠 RELATIVES - Reset Your Password

Hi {$userName},

We received a request to reset your password.

Click the link below to create a new password:
{$resetLink}

⏰ This link will expire in 1 hour for security.

⚠️ SECURITY NOTICE:
If you didn't request this password reset, please ignore this email and your password will remain unchanged.

---
© 2024 Relatives - Your Family Hub
This is an automated email. Please do not reply.
TEXT;
    }
    
    /**
     * Welcome Family Creator Email Template (HTML)
     */
    private function getWelcomeFamilyTemplate(string $userName, string $familyName, string $inviteCode): string {
        $loginUrl = $this->baseUrl . '/login.php';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Relatives!</title>
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
        .container { max-width: 600px; margin: 40px auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 48px 40px; text-align: center; }
        .logo { font-size: 64px; margin-bottom: 16px; }
        .header h1 { color: white; font-size: 36px; margin: 0; font-weight: 900; }
        .content { padding: 48px 40px; }
        .text { color: #4a5568; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0; }
        .invite-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 16px; padding: 32px; text-align: center; margin: 32px 0; }
        .invite-label { color: rgba(255,255,255,0.9); font-size: 14px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 12px; }
        .invite-code { color: white; font-size: 48px; font-weight: 900; letter-spacing: 8px; margin: 12px 0; font-family: 'Courier New', monospace; }
        .button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 18px 48px; border-radius: 12px; font-weight: 800; font-size: 16px; }
        .list { color: #4a5568; font-size: 15px; line-height: 1.8; padding-left: 24px; }
        .footer { background: #f7fafc; padding: 32px 40px; text-align: center; border-top: 1px solid #e2e8f0; }
        .footer-text { color: #718096; font-size: 13px; margin: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🎉</div>
            <h1>Welcome to Relatives!</h1>
        </div>
        
        <div class="content">
            <p class="text">Hi <strong>{$userName}</strong>,</p>
            <p class="text">
                Congratulations on creating <strong>{$familyName}</strong>! Your family hub is now live and ready for your family to join.
            </p>
            
            <div class="invite-box">
                <div class="invite-label">Your Family Invite Code</div>
                <div class="invite-code">{$inviteCode}</div>
                <p style="color: rgba(255,255,255,0.8); font-size: 13px; margin: 12px 0 0 0;">
                    Share this code with your family members
                </p>
            </div>
            
            <h3 style="color: #2d3748; font-size: 18px; margin: 32px 0 16px 0; font-weight: 700;">
                🎯 Next Steps:
            </h3>
            <ul class="list">
                <li>Share your invite code with family members</li>
                <li>Customize your family profile in Settings</li>
                <li>Start sharing photos and memories</li>
                <li>Create your family tree</li>
                <li>Plan events and celebrations together</li>
            </ul>
            
            <div style="text-align: center; margin-top: 32px;">
                <a href="{$loginUrl}" class="button">Go to Your Hub</a>
            </div>
        </div>
        
        <div class="footer">
            <p class="footer-text">© 2024 Relatives - Where families connect, beautifully</p>
            <p class="footer-text" style="color: #a0aec0; font-size: 12px; margin-top: 8px;">
                Need help? Reply to this email
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Welcome Family Creator Email Template (Plain Text)
     */
    private function getWelcomeFamilyTextTemplate(string $userName, string $familyName, string $inviteCode): string {
        return <<<TEXT
🎉 WELCOME TO RELATIVES!

Hi {$userName},

Congratulations on creating {$familyName}! Your family hub is now live and ready for your family to join.

YOUR FAMILY INVITE CODE:
{$inviteCode}

Share this code with your family members so they can join your hub.

🎯 NEXT STEPS:
- Share your invite code with family members
- Customize your family profile in Settings
- Start sharing photos and memories
- Create your family tree
- Plan events and celebrations together

Get started now: {$this->baseUrl}/login.php

---
© 2024 Relatives - Where families connect, beautifully
Need help? Reply to this email
TEXT;
    }
    
    /**
     * Welcome Member Email Template (HTML)
     */
    private function getWelcomeMemberTemplate(string $userName, string $familyName): string {
        $loginUrl = $this->baseUrl . '/login.php';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {$familyName}!</title>
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
        .container { max-width: 600px; margin: 40px auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 48px 40px; text-align: center; }
        .logo { font-size: 64px; margin-bottom: 16px; }
        .header h1 { color: white; font-size: 32px; margin: 0; font-weight: 900; }
        .content { padding: 48px 40px; }
        .text { color: #4a5568; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0; }
        .button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 18px 48px; border-radius: 12px; font-weight: 800; font-size: 16px; }
        .list { color: #4a5568; font-size: 15px; line-height: 1.8; padding-left: 24px; }
        .footer { background: #f7fafc; padding: 32px 40px; text-align: center; border-top: 1px solid #e2e8f0; }
        .footer-text { color: #718096; font-size: 13px; margin: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">👋</div>
            <h1>Welcome to {$familyName}!</h1>
        </div>
        
        <div class="content">
            <p class="text">Hi <strong>{$userName}</strong>,</p>
            <p class="text">
                You've successfully joined <strong>{$familyName}</strong> on Relatives! Your family is excited to connect and share memories with you.
            </p>
            
            <h3 style="color: #2d3748; font-size: 18px; margin: 32px 0 16px 0; font-weight: 700;">
                🌟 What You Can Do:
            </h3>
            <ul class="list">
                <li>View and share family photos</li>
                <li>Stay updated with family activities</li>
                <li>Join family events and celebrations</li>
                <li>Explore your family tree</li>
                <li>Connect with relatives</li>
            </ul>
            
            <div style="text-align: center; margin-top: 32px;">
                <a href="{$loginUrl}" class="button">Explore Your Hub</a>
            </div>
        </div>
        
        <div class="footer">
            <p class="footer-text">© 2024 Relatives - Where families connect, beautifully</p>
            <p class="footer-text" style="color: #a0aec0; font-size: 12px; margin-top: 8px;">
                Need help? Reply to this email
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Welcome Member Email Template (Plain Text)
     */
    private function getWelcomeMemberTextTemplate(string $userName, string $familyName): string {
        return <<<TEXT
👋 WELCOME TO {$familyName}!

Hi {$userName},

You've successfully joined {$familyName} on Relatives! Your family is excited to connect and share memories with you.

🌟 WHAT YOU CAN DO:
- View and share family photos
- Stay updated with family activities
- Join family events and celebrations
- Explore your family tree
- Connect with relatives

Get started now: {$this->baseUrl}/login.php

---
© 2024 Relatives - Where families connect, beautifully
Need help? Reply to this email
TEXT;
    }
}