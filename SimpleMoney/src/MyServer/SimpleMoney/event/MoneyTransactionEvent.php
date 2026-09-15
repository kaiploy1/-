<?php
// plugins/SimpleMoney/src/MyServer/SimpleMoney/event/MoneyTransactionEvent.php

declare(strict_types=1);

namespace MyServer\SimpleMoney\event;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\Event;

final class MoneyTransactionEvent extends Event implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private string $type,
		private ?string $fromUuid,
		private ?string $toUuid,
		private float $amount,
		private string $reason = ""
	){}

	public function getType() : string{
		return $this->type;
	}

	public function getFromUuid() : ?string{
		return $this->fromUuid;
	}

	public function getToUuid() : ?string{
		return $this->toUuid;
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

	public function setReason(string $reason) : void{
		$this->reason = $reason;
	}
}