<?php

namespace app\models\game;

readonly class InitialItem
{
    public function __construct(
        public Item $item,
        public int $count = 1
    ) {}
}
