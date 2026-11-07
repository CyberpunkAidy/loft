<?php

namespace Tests\Feature;

use App\Events\MemberJoined;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Resend\Client as ResendClient;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;
use Tests\TestCase;

/** MAIL_MAILER=resend: studio emails go out through Resend's API (resend/resend-php). */
class ResendMailTest extends TestCase
{
    public function test_resend_mailer_is_available(): void
    {
        config(['services.resend.key' => 're_test_key']);

        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());
    }

    public function test_studio_emails_are_sent_through_resend(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => 're_test_key', 'mail.from.address' => 'hello@mail.loft.test']);

        // Capture the API call instead of reaching api.resend.com.
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'email_123'])),
        ]));
        $stack->push(Middleware::history($sent));
        $client = new ResendClient(new HttpTransporter(
            new GuzzleClient(['handler' => $stack]),
            BaseUri::from('api.resend.com'),
            Headers::withAuthorization(ApiKey::from('re_test_key')),
        ));
        Mail::mailer('resend')->setSymfonyTransport(new ResendTransport($client));

        $studio = $this->studio(['name' => 'Skyline Aerial', 'email_from_name' => 'Skyline Aerial', 'contact_email' => 'front-desk@skyline.test']);
        $user = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);

        MemberJoined::dispatch($studio, $user); // sends the welcome email

        $this->assertCount(1, $sent);
        $request = $sent[0]['request'];
        $this->assertSame('https://api.resend.com/emails', (string) $request->getUri());
        $this->assertSame('Bearer re_test_key', $request->getHeaderLine('Authorization'));

        $email = json_decode((string) $request->getBody(), true);
        $this->assertSame('"Skyline Aerial" <hello@mail.loft.test>', $email['from']);
        $this->assertStringContainsString('pat@example.com', $email['to'][0]);
        $this->assertStringContainsString('front-desk@skyline.test', $email['reply_to'][0]);
        $this->assertSame('[Skyline Aerial] Welcome!', $email['subject']);
        $this->assertStringContainsString('Your account is ready', $email['html']);
        $this->assertStringContainsString('Your account is ready', $email['text']);
    }
}
