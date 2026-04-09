<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $acceptUrl;

    public function __construct(
        public readonly WorkspaceInvitation $invitation,
        public readonly Workspace $workspace,
        public readonly bool $userExists,
    ) {
        // Existing users go to login with token; new users go to register with token
        $route = $userExists ? 'login' : 'register';
        $this->acceptUrl = url("/{$route}?invitation={$invitation->token}");
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->workspace->name} on Nexstage",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.workspace-invitation',
        );
    }
}
