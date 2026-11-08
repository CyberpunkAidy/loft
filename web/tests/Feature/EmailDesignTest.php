<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\User;
use App\Support\Color;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\TestCase;

class EmailDesignTest extends TestCase
{
    public function test_studio_emails_carry_the_studios_brand(): void
    {
        $studio = $this->studio(['name' => 'Skyline Aerial', 'accent_color' => '#facc15', 'contact_email' => 'hi@skyline.test']);
        $mail = new StudioMessage($studio, "You're booked!", ['See you in class.'], ['Class' => 'Kids Silks', 'When' => 'Tue 4:30 PM'],
            ['Cancel 12 hours ahead to keep your credit.'], 'https://example.test/account', 'View your classes', 'booked');

        $html = $mail->render();
        $this->assertStringContainsString('See you in class.&#8199;', $html); // inbox preview text
        $this->assertStringContainsString('>S</td>', $html); // the studio's initial badge
        $this->assertStringContainsString('background:#facc15;', $html);
        // A light brand color gets dark button text and a darkened link color.
        $this->assertStringContainsString('color:'.Color::INK.';text-decoration:none;">View your classes&nbsp;&rarr;</a>', $html);
        $this->assertStringContainsString('style="color:'.Color::textOnWhite('#facc15').';">your account</a>', $html);
        $this->assertStringContainsString('Kids Silks', $html);
        $this->assertStringContainsString(asset('images/loft-logo-email.png'), $html);

        $text = view('mail.studio-message-text', $mail->buildViewData())->render();
        $this->assertStringContainsString("View your classes: https://example.test/account\n", $text);
        $this->assertStringContainsString('Questions? Just reply to this email.', $text);
    }

    public function test_password_emails_are_loft_branded(): void
    {
        $user = User::factory()->create(['email' => 'pat@example.com']);
        $mail = (new ResetPassword('tok123'))->toMail($user);

        $this->assertSame('Choose your '.config('app.name').' password', $mail->subject);
        $url = route('password.reset', ['token' => 'tok123', 'email' => 'pat@example.com']);
        $html = (string) $mail->render();
        $this->assertStringContainsString('Choose your password', $html);
        $this->assertStringContainsString('href="'.e($url).'"', $html);
        $this->assertStringContainsString('The link works for 60 minutes.', $html);
        $this->assertStringContainsString(asset('images/loft-logo-email.png'), $html);
        $this->assertSame(['mail.password', 'mail.password-text'], $mail->view);

        // The link in the email opens the reset form.
        $this->get($url)->assertOk();
    }
}
