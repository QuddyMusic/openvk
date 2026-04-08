<?php
declare(strict_types=1);
namespace openvk\Web\Presenters;
use Chandler\Database\DatabaseConnection;
use Chandler\Signaling\SignalManager;
use openvk\Web\Events\NewMessageEvent;
use openvk\Web\Models\Repositories\{Users, Clubs, Messages};
use openvk\Web\Models\Entities\{Message, Correspondence};

final class MessengerPresenter extends OpenVKPresenter
{
    private $messages;
    private $signaler;
    protected $presenterName = "messenger";

    public function __construct(Messages $messages)
    {
        $this->messages = $messages;
        $this->signaler = SignalManager::i();
        parent::__construct();
    }

    private function getCorrespondent(int $id): object
    {
        if ($id > 0)      return (new Users())->get($id);
        elseif ($id < 0)  return (new Clubs())->get(abs($id));
        else              return $this->user->identity;
    }

    public function renderIndex(): void
    {
        $this->assertUserLoggedIn();
        if (isset($_GET["sel"])) {
            $this->pass("openvk!Messenger->app", $_GET["sel"]);
        }
        $page            = (int) ($_GET["p"] ?? 1);
        $correspondences = iterator_to_array($this->messages->getCorrespondencies($this->user->identity, $page));
        $this->template->corresps     = $correspondences;
        $this->template->paginatorConf = (object) [
            "count"   => $this->messages->getCorrespondenciesCount($this->user->identity),
            "page"    => $page,
            "amount"  => sizeof($correspondences),
            "perPage" => OPENVK_DEFAULT_PER_PAGE,
            "tidy"    => false,
            "atTop"   => false,
        ];
    }

    public function renderApp(int $sel): void
    {
        $this->assertUserLoggedIn();
        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) $this->notFound();

        if (!$this->user->identity->getPrivacyPermission('messages.write', $correspondent)) {
            $this->flash("err", tr("warning"), tr("user_may_not_reply"));
        }
        $this->template->disable_ajax  = 1;
        $this->template->selId         = $sel;
        $this->template->correspondent = $correspondent;
    }

    public function renderEvents(int $randNum): void
    {
        $this->assertUserLoggedIn();
        header("Content-Type: application/json");
        $this->signaler->listen(function ($event, $id) {
            exit(json_encode([[
                "UUID"  => $id,
                "event" => $event->getLongPoolSummary(),
            ]]));
        }, $this->user->id);
    }

    public function renderVKEvents(int $id): void
    {
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json");
        if ($this->queryParam("act") !== "a_check") { header("HTTP/1.1 400 Bad Request"); exit(); }
        if (!$this->queryParam("key"))               { header("HTTP/1.1 403 Forbidden");   exit(); }

        $key       = $this->queryParam("key");
        $payload   = hex2bin(substr($key, 0, 16));
        $signature = hex2bin(substr($key, 16));
        if (($signature ^ (~CHANDLER_ROOT_CONF["security"]["secret"] | ((string) $id))) !== $payload) {
            exit(json_encode(["failed" => 3]));
        }

        $time = intval($this->queryParam("wait"));
        if ($time > 60) $time = 60;
        elseif ($time == 0) $time = 25;

        $this->signaler->listen(function ($event, $eId) use ($id) {
            exit(json_encode(["ts" => time(), "updates" => [$event->getVKAPISummary($id)]]));
        }, $id, $time);
    }

    public function renderApiGetMessages(int $sel, int $lastMsg): void
    {
        $this->assertUserLoggedIn();
        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) $this->notFound();

        $messages       = [];
        $correspondence = new Correspondence($this->user->identity, $correspondent);
        foreach ($correspondence->getMessages(1, $lastMsg === 0 ? null : $lastMsg, null, 0) as $message) {
            $messages[] = $message->simplify();
        }
        header("Content-Type: application/json");
        exit(json_encode($messages));
    }

    /**
     * GET /im/api/correspondences.json
     * Returns list of conversation partners for forward modal.
     */
    public function renderApiGetCorrespondences(): void
	{
	    $this->assertUserLoggedIn();
	    header("Content-Type: application/json");

	    $result = [];

	    foreach ($this->messages->getCorrespondencies($this->user->identity, 1, 50) as $corr) {
		$correspondents = $corr->getCorrespondents();
		// partner — тот, кто не текущий пользователь
		$partner = ($correspondents[0]->getId() === $this->user->id)
		    ? $correspondents[1]
		    : $correspondents[0];

		if (!$partner) continue;
		// не пропускаем self-диалог — он тоже нужен

		$id = get_class($partner) === 'openvk\Web\Models\Entities\Club'
		    ? $partner->getId() * -1
		    : $partner->getId();

		$result[] = [
		    "id"     => $id,
		    "name"   => $partner->getCanonicalName(),
		    "avatar" => $partner->getAvatarUrl('miniscule'),
		    "url"    => $partner->getURL(),
		];
	    }

	    exit(json_encode($result));
	}

    public function renderApiWriteMessage(int $sel): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        // ── attachments ──────────────────────────────────────────────────────
        $attachments = [];
        if (!empty($this->postParam("attachments"))) {
            $attachmentIds = array_slice(explode(",", $this->postParam("attachments")), 0, 10);
            $attachments   = parseAttachments($attachmentIds, ['photo', 'video', 'audio', 'doc', 'note', 'poll']);
        }

        // ── reply_to / forwarded_ids ──────────────────────────────────────────
        $replyTo      = null;
        $forwardedIds = null;

        if (!empty($this->postParam("reply_to"))) {
            $replyTo = (int) $this->postParam("reply_to");
        }
        if (!empty($this->postParam("forwarded_ids"))) {
            $ids          = array_slice(array_map('intval', explode(",", $this->postParam("forwarded_ids"))), 0, 20);
            $forwardedIds = json_encode(array_values(array_filter($ids)));
        }

        // ── validate ─────────────────────────────────────────────────────────
        $hasContent = !empty($this->postParam("content"));
        if (!$hasContent && count($attachments) === 0 && !$replyTo && !$forwardedIds) {
            header("HTTP/1.1 400 Bad Request");
            exit(json_encode(["error" => "Need text, attachments, reply_to, or forwarded_ids"]));
        }

        $sel = $this->getCorrespondent($sel);
        if ($sel->getId() !== $this->user->id && !$sel->getPrivacyPermission('messages.write', $this->user->identity)) {
            header("HTTP/1.1 403 Forbidden"); exit();
        }

        $cor = new Correspondence($this->user->identity, $sel);
        $msg = new Message();
        $msg->setContent($this->postParam("content") ?? "");
        $cor->sendMessage($msg);

        foreach ($attachments as $attachment) {
            if (!$attachment || $attachment->isDeleted() || !$attachment->canBeViewedBy($this->user->identity)) continue;
            $msg->attach($attachment);
        }

        // ── save reply_to / forwarded_ids via direct DB update ────────────────
        if ($replyTo || $forwardedIds) {
            $upd = [];
            if ($replyTo)      $upd['reply_to']      = $replyTo;
            if ($forwardedIds) $upd['forwarded_ids'] = $forwardedIds;
            DatabaseConnection::i()->getContext()
                ->table('messages')->where('id', $msg->getId())->update($upd);
	    $msgRow = DatabaseConnection::i()->getContext()
		->table('messages')->where('id', $msg->getId())->fetch();
	    $msg = new Message($msgRow);
        }

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        exit(json_encode($msg->simplify()));
    }
}
