<?php
// src/MyServer/SimpleMoney/form/ModalForm.php

declare(strict_types=1);

namespace MyServer\SimpleMoney\form;

use pocketmine\form\Form;
use pocketmine\player\Player;

final class ModalForm implements Form{

	/** @param \Closure(Player, bool): void $onSubmit */
	public function __construct(
		private string $title,
		private string $content,
		private string $buttonYes,
		private string $buttonNo,
		private \Closure $onSubmit
	){}

	public function handleResponse(Player $player, mixed $data) : void{
		if(!is_bool($data)){
			return;
		}

		($this->onSubmit)($player, $data);
	}

	public function jsonSerialize() : mixed{
		return [
			"type" => "modal",
			"title" => $this->title,
			"content" => $this->content,
			"button1" => $this->buttonYes,
			"button2" => $this->buttonNo
		];
	}
}