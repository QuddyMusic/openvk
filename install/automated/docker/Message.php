<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Repositories\Clubs;
use openvk\Web\Models\Repositories\Users;
use openvk\Web\Models\Entities\Photo;
use openvk\Web\Models\Entities\Audio;
use openvk\Web\Models\Entities\Video;
use openvk\Web\Models\Entities\Document;
use openvk\Web\Models\RowModel;
use openvk\Web\Util\DateTime;

/**
 * Message entity.
 */
class Message extends RowModel
{
    use Traits\TRichText;
    use Traits\TAttachmentHost;
    protected $tableName = "messages";

    /**
     * Get origin of the message.
     *
     * Returns either user or club.
     *
     * @returns User|Club
     */
    public function getSender(): ?RowModel
    {
        if ($this->getRecord()->sender_type === 'openvk\Web\Models\Entities\User') {
            return (new Users())->get($this->getRecord()->sender_id);
        } elseif ($this->getRecord()->sender_type === 'openvk\Web\Models\Entities\Club') {
            return (new Clubs())->get($this->getRecord()->sender_id);
        } else {
            return null;
        }
    }

    /**
     * Get the destination of the message.
     *
     * Returns either user or club.
     *
     * @returns User|Club
     */
    public function getRecipient(): ?RowModel
    {
        if ($this->getRecord()->recipient_type === 'openvk\Web\Models\Entities\User') {
            return (new Users())->get($this->getRecord()->recipient_id);
        } elseif ($this->getRecord()->recipient_type === 'openvk\Web\Models\Entities\Club') {
            return (new Clubs())->get($this->getRecord()->recipient_id);
        } else {
            return null;
        }
    }

    public function getUnreadState(): int
    {
        trigger_error("TODO: use isUnread", E_USER_DEPRECATED);

        return (int) $this->isUnread();
    }

    /**
     * Get date of initial publication.
     *
     * @returns DateTime
     */
    public function getSendTime(): DateTime
    {
        return new DateTime($this->getRecord()->created);
    }

    public function getSendTimeHumanized(): string
    {
        $dateTime = new DateTime($this->getRecord()->created);

        if ($dateTime->format("%d.%m.%y") == ovk_strftime_safe("%d.%m.%y", time())) {
            return $dateTime->format("%T");
        } else {
            return $dateTime->format("%d.%m.%y");
        }
    }

    /**
     * Get date of last edit, if any edits were made, otherwise null.
     *
     * @returns DateTime|null
     */
    public function getEditTime(): ?DateTime
    {
        $edited = $this->getRecord()->edited;
        if (is_null($edited)) {
            return null;
        }

        return new DateTime($edited);
    }

    /**
     * Is this message an ad?
     *
     * Messages can never be ads.
     *
     * @returns false
     */
    public function isAd(): bool
    {
        return false;
    }

    public function isUnread(): bool
    {
        return (bool) $this->getRecord()->unread;
    }

    /**
     * Simplify to array
     *
     * @returns array
     */
    public function simplify(): array
    {
        $author = $this->getSender();

        $attachments = [];
        foreach ($this->getChildren() as $attachment) {
	    if ($attachment instanceof Photo) {
		$attachments[] = [
		    "type"     => "photo",
		    "link"     => "/photo" . $attachment->getPrettyId(),
		    "id"       => $attachment->getPrettyId(),
		    "photo"    => [
			"url"      => $attachment->getURLBySizeId('larger'),
			"tiny_url" => $attachment->getURL(),
			"caption"  => $attachment->getDescription(),
		    ],
		];
	    } elseif ($attachment instanceof Audio) {
		    $id = $attachment->getId() . rand(0, 1000);
		    $isAvailable = $attachment->isAvailable();
		    $isWithdrawn = $attachment->isWithdrawn();
		    $performer = htmlspecialchars($attachment->getPerformer());
		    $title = htmlspecialchars($attachment->getTitle());
		    $name = htmlspecialchars($attachment->getName());
		    $length = $attachment->getLength();
		    $formattedLength = $attachment->getFormattedLength();
		    $genre = htmlspecialchars($attachment->getGenre() ?? '');
		    $keys = htmlspecialchars(json_encode($attachment->getKeys()));
		    $url = htmlspecialchars($attachment->getURL());
		    $ownerId = $attachment->getOwner()->getId();
		    $prettyId = $attachment->getPrettyId();
		    $embedClasses = "audioEmbed ctx_place" . (!$isAvailable ? " processed" : "") . ($isWithdrawn ? " withdrawn" : "");
		    
		    $html = "<div id=\"audioEmbed-{$id}\" data-realid=\"{$attachment->getId()}\" data-name=\"{$name}\" data-genre=\"{$genre}\" class=\"{$embedClasses}\" data-length=\"{$length}\" data-keys=\"{$keys}\" data-url=\"{$url}\" data-owner-id=\"{$ownerId}\">";
		    $html .= "<audio class=\"audio\"></audio>";
		    $html .= "<div id=\"miniplayer\" class=\"audioEntry\">";
		    $html .= "<div class=\"audioEntryWrapper\" draggable=\"true\">";
		    $html .= "<div class=\"playerButton\"><div class=\"playIcon\"></div></div>";
		    $html .= "<div class=\"status\"><div class=\"mediaInfo noOverflow\"><div class=\"info\">";
		    $html .= "<strong class=\"performer\"><a draggable=\"false\" href=\"/search?section=audios&order=listens&only_performers=on&q=" . urlencode($performer) . "\">{$performer}</a></strong>";
		    $html .= " — <span draggable=\"false\" class=\"title\">{$title}</span>";
		    $html .= "</div></div></div>";
		    $html .= "<div class=\"mini_timer\">";
		    $html .= "<span class=\"nobold hideOnHover\" data-unformatted=\"{$length}\">{$formattedLength}</span>";
		    $html .= "<div class=\"buttons\">";
		    $html .= "<div class=\"add-icon musicIcon hovermeicon\" data-id=\"{$attachment->getId()}\"></div>";
		    $html .= "</div></div></div>";
		    if (!$isWithdrawn) {
			$html .= "<div class=\"subTracks\" draggable=\"false\">";
			$html .= "<div class=\"lengthTrackWrapper\"><div class=\"track lengthTrack\"><div class=\"selectableTrack\">";
			$html .= "<div class=\"selectableTrackLoadProgress\"><div class=\"load_bar\"></div></div>";
			$html .= "<div class=\"selectableTrackSlider\"><div class=\"slider\"></div></div>";
			$html .= "</div></div></div>";
			$html .= "<div class=\"volumeTrackWrapper\"><div class=\"track volumeTrack\"><div class=\"selectableTrack\">";
			$html .= "<div class=\"selectableTrackSlider\"><div class=\"slider\"></div></div>";
			$html .= "</div></div></div>";
			$html .= "</div>";
		    }
		    $html .= "</div></div>";

		    $attachments[] = [
			"type" => "audio",
			"link" => "/audio{$prettyId}",
			"html" => $html,
			"audio" => [
			    "name"   => $attachment->getName(),
			    "artist" => $attachment->getPerformer(),
			    "url"    => $attachment->getURL(),
			],
		    ];
	    } elseif ($attachment instanceof Video) {
		$attachments[] = [
		    "type"  => "video",
		    "link"  => "/video" . $attachment->getPrettyId(),
		    "video" => [
			"name"      => $attachment->getName(),
			"thumbnail" => $attachment->getThumbnailURL(),
		    ],
		];
	    } elseif ($attachment instanceof Document) {
		$attachments[] = [
		    "type" => "doc",
		    "link" => "/doc" . $attachment->getPrettyId(),
		    "doc"  => [
			"name" => $attachment->getName(),
		    ],
		];
	    }
	}

        return [
            "uuid"   => $this->getId(),
            "sender" => [
                "id"     => $author->getId(),
                "link"   => $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . $author->getURL(),
                "avatar" => $author->getAvatarUrl(),
                "name"   => $author->getFirstName(),
            ],
            "timing" => [
                "sent"   => (string) $this->getSendTimeHumanized(),
                "edited" => is_null($this->getEditTime()) ? null : (string) $this->getEditTime(),
            ],
            "text"        => $this->getText(),
            "read"        => !$this->isUnread(),
            "attachments" => $attachments,
        ];
    }
}
