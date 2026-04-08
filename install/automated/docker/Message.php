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

class Message extends RowModel
{
    use Traits\TRichText;
    use Traits\TAttachmentHost;
    protected $tableName = "messages";

    public function getSender(): ?RowModel
    {
        if ($this->getRecord()->sender_type === 'openvk\Web\Models\Entities\User') {
            return (new Users())->get($this->getRecord()->sender_id);
        } elseif ($this->getRecord()->sender_type === 'openvk\Web\Models\Entities\Club') {
            return (new Clubs())->get($this->getRecord()->sender_id);
        }
        return null;
    }

    public function getRecipient(): ?RowModel
    {
        if ($this->getRecord()->recipient_type === 'openvk\Web\Models\Entities\User') {
            return (new Users())->get($this->getRecord()->recipient_id);
        } elseif ($this->getRecord()->recipient_type === 'openvk\Web\Models\Entities\Club') {
            return (new Clubs())->get($this->getRecord()->recipient_id);
        }
        return null;
    }

    public function getUnreadState(): int
    {
        trigger_error("TODO: use isUnread", E_USER_DEPRECATED);
        return (int) $this->isUnread();
    }

    public function getSendTime(): DateTime
    {
        return new DateTime($this->getRecord()->created);
    }

    public function getSendTimeHumanized(): string
    {
        $dateTime = new DateTime($this->getRecord()->created);
        if ($dateTime->format("%d.%m.%y") == ovk_strftime_safe("%d.%m.%y", time())) {
            return $dateTime->format("%T");
        }
        return $dateTime->format("%d.%m.%y");
    }

    public function getEditTime(): ?DateTime
    {
        $edited = $this->getRecord()->edited;
        if (is_null($edited)) return null;
        return new DateTime($edited);
    }

    public function isAd(): bool { return false; }

    public function isUnread(): bool
    {
        return (bool) $this->getRecord()->unread;
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function _buildSenderArray(\openvk\Web\Models\RowModel $author): array
    {
        return [
            "id"     => $author->getId(),
            "link"   => $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . $author->getURL(),
            "avatar" => $author->getAvatarUrl(),
            "name"   => $author->getFirstName(),
        ];
    }

    private function _simplifyReplyTo(): ?array
    {
        $rid = $this->getRecord()->reply_to;
        if (!$rid) return null;

        $db  = DatabaseConnection::i()->getContext();
        $row = $db->table('messages')->where('id', (int)$rid)->fetch();
        if (!$row) return null;

        $rMsg    = new Message($row);
        $rSender = $rMsg->getSender();
        if (!$rSender) return null;

        return [
            "uuid"   => $rMsg->getId(),
            "sender" => [
                "name"   => $rSender->getFirstName(),
                "avatar" => $rSender->getAvatarUrl(),
            ],
            "text" => mb_substr(strip_tags($rMsg->getText()), 0, 120),
        ];
    }

    private function _simplifyForwarded(): array
	{
	    $raw = $this->getRecord()->forwarded_ids;
	    if (!$raw) return [];

	    $ids = json_decode($raw, true);
	    if (!is_array($ids)) return [];

	    $db     = DatabaseConnection::i()->getContext();
	    $result = [];

	    foreach (array_slice($ids, 0, 20) as $fid) {
		$fRow = $db->table('messages')->where('id', (int)$fid)->fetch();
		if (!$fRow) continue;

		$fMsg    = new Message($fRow);
		$fSender = $fMsg->getSender();
		if (!$fSender) continue;

		// conv_id — отсортированная пара sender_id:recipient_id
		// идентифицирует исходный диалог
		$ids_pair = [(int)$fRow->sender_id, (int)$fRow->recipient_id];
		sort($ids_pair);
		$convId = $ids_pair[0] . '_' . $ids_pair[1];

		// используем simplify() чтобы получить вложения
		$simplified = $fMsg->simplify();

		$result[] = [
		    "uuid"        => $fMsg->getId(),
		    "conv_id"     => $convId,
		    "sender"      => [
			"name"   => $fSender->getFirstName(),
			"avatar" => $fSender->getAvatarUrl(),
			"link"   => $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . $fSender->getURL(),
		    ],
		    "text"        => $fMsg->getText(),
		    "timing"      => ["sent" => (string) $fMsg->getSendTimeHumanized()],
		    "attachments" => $simplified['attachments'],
		    "forwarded"   => $fMsg->_simplifyForwarded(),
		];
	    }

	    return $result;
	}

    // ─── simplify ─────────────────────────────────────────────────────────────

    public function simplify(): array
    {
        $author      = $this->getSender();
        $attachments = [];

        foreach ($this->getChildren() as $attachment) {
            if ($attachment instanceof Photo) {
                $attachments[] = [
                    "type"  => "photo",
                    "link"  => "/photo" . $attachment->getPrettyId(),
                    "id"    => $attachment->getPrettyId(),
                    "photo" => [
                        "url"      => $attachment->getURLBySizeId('larger'),
                        "tiny_url" => $attachment->getURL(),
                        "caption"  => $attachment->getDescription(),
                    ],
                ];
            } elseif ($attachment instanceof Audio) {
                $id              = "msg" . $this->getId() . "_aud" . $attachment->getId();
                $isAvailable     = $attachment->isAvailable();
                $isWithdrawn     = $attachment->isWithdrawn();
                $performer       = htmlspecialchars($attachment->getPerformer());
                $title           = htmlspecialchars($attachment->getTitle());
                $name            = htmlspecialchars($attachment->getName());
                $length          = $attachment->getLength();
                $formattedLength = $attachment->getFormattedLength();
                $genre           = htmlspecialchars($attachment->getGenre() ?? '');
                $keys            = htmlspecialchars(json_encode($attachment->getKeys()));
                $url             = htmlspecialchars($attachment->getURL());
                $ownerId         = $attachment->getOwner()->getId();
                $prettyId        = $attachment->getPrettyId();
                $embedClasses    = "audioEmbed ctx_place" . (!$isAvailable ? " processed" : "") . ($isWithdrawn ? " withdrawn" : "");

                $html  = "<div id=\"audioEmbed-{$id}\" data-realid=\"{$attachment->getId()}\" data-name=\"{$name}\" data-genre=\"{$genre}\" class=\"{$embedClasses}\" data-length=\"{$length}\" data-keys=\"{$keys}\" data-url=\"{$url}\" data-owner-id=\"{$ownerId}\">";
                $html .= "<audio class=\"audio\"></audio>";
                $html .= "<div id=\"miniplayer\" class=\"audioEntry\"><div class=\"audioEntryWrapper\" draggable=\"true\">";
                $html .= "<div class=\"playerButton\"><div class=\"playIcon\"></div></div>";
                $html .= "<div class=\"status\"><div class=\"mediaInfo noOverflow\"><div class=\"info\">";
                $html .= "<strong class=\"performer\"><a draggable=\"false\" href=\"/search?section=audios&order=listens&only_performers=on&q=" . urlencode($performer) . "\">{$performer}</a></strong>";
                $html .= " — <span draggable=\"false\" class=\"title\">{$title}</span>";
                $html .= "</div></div></div>";
                $html .= "<div class=\"mini_timer\"><span class=\"nobold hideOnHover\" data-unformatted=\"{$length}\">{$formattedLength}</span>";
                $html .= "<div class=\"buttons\">";
                $html .= "<div class=\"add-icon musicIcon hovermeicon\" data-id=\"{$attachment->getId()}\"></div>";
                $html .= "<a class=\"download-icon musicIcon\" href=\"" . htmlspecialchars($attachment->getOriginalURL()) . "\" download=\"" . htmlspecialchars($attachment->getDownloadName()) . "\"></a>";
                $html .= "<div class=\"edit-icon musicIcon\" data-album-id=\"{$attachment->getAlbumId()}\" data-lyrics=\"" . htmlspecialchars($attachment->getLyrics() ?? '') . "\" data-title=\"{$title}\" data-performer=\"{$performer}\" data-explicit=\"{$attachment->isExplicit()}\" data-searchable=\"" . ((int)!$attachment->isUnlisted()) . "\" data-owner-id=\"{$ownerId}\"></div>";
                $html .= "</div></div></div>";
                if (!$isWithdrawn) {
                    $html .= "<div class=\"subTracks\" draggable=\"false\">";
                    $html .= "<div class=\"lengthTrackWrapper\"><div class=\"track lengthTrack\"><div class=\"selectableTrack\">";
                    $html .= "<div class=\"selectableTrackLoadProgress\"><div class=\"load_bar\"></div></div>";
                    $html .= "<div class=\"selectableTrackSlider\"><div class=\"slider\"></div></div>";
                    $html .= "</div></div></div>";
                    $html .= "<div class=\"volumeTrackWrapper\"><div class=\"track volumeTrack\"><div class=\"selectableTrack\">";
                    $html .= "<div class=\"selectableTrackSlider\"><div class=\"slider\"></div></div>";
                    $html .= "</div></div></div></div>";
                }
                $html .= "</div></div>";

                $attachments[] = [
                    "type"  => "audio",
                    "link"  => "/audio{$prettyId}",
                    "html"  => $html,
                    "audio" => ["name" => $attachment->getName(), "artist" => $attachment->getPerformer(), "url" => $attachment->getURL()],
                ];

            } elseif ($attachment instanceof Video) {
                $name     = htmlspecialchars($attachment->getName());
                $prettyId = $attachment->getPrettyId();
                if ($attachment->getType() === 0) {
                    $url  = $attachment->getURL();
                    $html = "<div style='width:100%'><div class='media' data-name='{$name}'>";
                    $html .= "<video class='media' src='{$url}' controls style='max-width:100%;max-height:300px;'></video>";
                    $html .= "</div><div class='video-wowzer'><div class='small-video-ico'></div>";
                    $html .= "<a href='/video{$prettyId}' id='videoOpen' data-id='{$prettyId}'>{$name}</a></div></div>";
                    $thumbnail = $attachment->getThumbnailURL();
                } else {
                    $driver = $attachment->getVideoDriver();
                    if ($driver) {
                        $html  = "<div style='width:100%;max-width:100%;'>" . $driver->getEmbed("100%", "225");
                        $html .= "<div class='video-wowzer'><div class='small-video-ico'></div>";
                        $html .= "<a href='/video{$prettyId}' id='videoOpen' data-id='{$prettyId}'>{$name}</a></div></div>";
                    } else {
                        $html = "<a href='/video{$prettyId}'>{$name}</a>";
                    }
                    $thumbnail = "";
                }
                $attachments[] = [
                    "type"  => "video",
                    "link"  => "/video{$prettyId}",
                    "html"  => $html,
                    "video" => ["name" => $name, "thumbnail" => $thumbnail],
                ];

            } elseif ($attachment instanceof Document) {
                $name        = htmlspecialchars($attachment->getName());
                $prettyId    = $attachment->getPrettyId();
                $prettiestId = $attachment->getPrettiestId();
                $accessKey   = $attachment->getAccessKey();
                $link        = "/doc{$prettyId}?key={$accessKey}";
                $size        = readable_filesize($attachment->getFilesize());

                if ($attachment->isImage()) {
                    $preview    = $attachment->hasPreview() ? $attachment->getPreview() : null;
                    $previewUrl = $preview ? $preview->getURLBySizeId('medium') : '';
                    $isGif      = $attachment->isGif();
                    $gifUrl     = $isGif ? htmlspecialchars($attachment->getURL()) : '';

                    $html  = "<a href='{$link}' class='docMainItem viewerOpener docGalleryItem" . ($isGif ? " embeddable" : "") . "' data-id='{$prettiestId}' style='display:block;max-width:300px;'>";
                    $html .= "<img class='docGalleryItem_main_preview' loading='lazy' src='{$previewUrl}' alt='gallery photo' style='max-width:100%;'>";
                    if ($isGif) {
                        $html .= "<div class='play-button'><div class='play-button-ico'></div></div>";
                        $html .= "<img class='docGalleryItem_gif_preview' loading='lazy' src='{$gifUrl}' alt='gif photo view'>";
                    }
                    $html .= "<div class='doc_top_panel doc_shown_by_hover'><div class='doc_volume_action' id='add_icon'></div></div>";
                    $html .= "<div class='doc_bottom_panel doc_content'>";
                    $html .= "<span class='doc_bottom_panel_name noOverflow doc_name'>{$name}</span>";
                    $html .= "<span class='doc_bottom_panel_size'>{$size}</span></div></a>";
                } else {
                    $ext  = htmlspecialchars($attachment->getFileExtension());
                    $date = (string) $attachment->getPublicationTime();
                    if ($attachment->hasPreview()) {
                        $preview = $attachment->getPreview();
                        $iconHtml = "<img class='doc_icon' alt='document_preview' src='{$preview->getURLBySizeId('tiny')}'>";
                    } else {
                        $iconHtml = "<div class='doc_icon no_image'><span>{$ext}</span></div>";
                    }
                    $html  = "<div class='docMainItem docListViewItem' data-id='{$prettiestId}' style='width:100%;box-sizing:border-box;'>";
                    $html .= "<a class='viewerOpener' href='{$link}'>{$iconHtml}</a>";
                    $html .= "<div class='doc_content noOverflow' style='flex:1;min-width:0;'>";
                    $html .= "<a class='viewerOpener noOverflow' href='{$link}'><b class='noOverflow doc_name'>{$name}</b></a>";
                    $html .= "<div class='doc_content_info'><span>{$date}</span> <span>{$size}</span></div></div>";
                    $html .= "<div class='doc_volume'><div id='edit_icon'></div><div id='add_icon'></div></div></div>";
                }
                $attachments[] = [
                    "type" => "doc",
                    "link" => $link,
                    "html" => $html,
                    "doc"  => ["name" => $attachment->getName()],
                ];
            }
        }

        return [
            "uuid"       => $this->getId(),
            "sender"     => $this->_buildSenderArray($author),
            "timing"     => [
                "sent"   => (string) $this->getSendTimeHumanized(),
                "edited" => is_null($this->getEditTime()) ? null : (string) $this->getEditTime(),
            ],
            "text"        => $this->getText(),
            "read"        => !$this->isUnread(),
            "attachments" => $attachments,
            "reply_to"    => $this->_simplifyReplyTo(),
            "forwarded"   => $this->_simplifyForwarded(),
        ];
    }
}
