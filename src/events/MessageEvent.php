<?php

namespace justinholtweb\pigeon\events;

use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\records\MessageRecord;
use yii\base\Event;

class MessageEvent extends Event
{
    public MessageRecord $message;
    public Thread $thread;
    public bool $isNew = true;
}
