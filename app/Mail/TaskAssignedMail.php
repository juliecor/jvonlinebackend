<?php

namespace App\Mail;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To someone in a realty: an admin gave them a task. */
class TaskAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Task $task, public User $assignee, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->task->priority === 'urgent' ? 'Urgent task: ' : 'New task: ').$this->task->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.task-assigned');
    }
}
