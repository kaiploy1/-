<?php
// src/MyServer/SimpleMoney/event/BankTransactionEvent.php

declare(strict_types=1);

namespace MyServer\SimpleMoney\event;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\Event;

final class BankTransactionEvent extends Event implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private string $type,
		private string $uuid,
		private float $amount,
		private string $reason = ""
	){}

	public function getType() : string{
		return $this->type;
	}

	public function getUuid() : string{
		return $this->uuid;
	}

	public function getAmount() : float{
		return $this->amount;
	}

	public function setAmount(float $amount) : void{
		if(!is_finite($amount) || $amount < 0){
			throw new \InvalidArgumentException("จำนวนเงินไม่ถูกต้อง");
		}

		$this->amount = round($amount, 2);
	}

	public function getReason() : string{
		return $this->reason;
	}
}