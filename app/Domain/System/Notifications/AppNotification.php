<?php

namespace App\Domain\System\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app notification shown under the bell in the top bar.
 * Subclasses only say what it is about: title, message and the page to open.
 */
abstract class AppNotification extends Notification
{
    abstract public function title(): string;

    abstract public function message(): string;

    abstract public function url(): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }
}
