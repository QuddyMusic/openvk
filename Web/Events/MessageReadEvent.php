<?php
declare(strict_types=1);
namespace openvk\Web\Events;

class MessageReadEvent implements ILPEmitable
{
    private int $readerId;
    private int $correspondentId;

    public function __construct(int $readerId, int $correspondentId)
    {
        $this->readerId        = $readerId;
        $this->correspondentId = $correspondentId;
    }

    public function getLongPoolSummary(): object
    {
        return (object) [
            "type"            => "messagesRead",
            "reader_id"       => $this->readerId,
            "correspondent_id"=> $this->correspondentId,
        ];
    }
}
