<?php
// src/MyServer/SimpleMoney/form/CustomForm.php

declare(strict_types=1);

namespace MyServer\SimpleMoney\form;

use pocketmine\form\Form;
use pocketmine\player\Player;

final class CustomForm implements Form{

	/** @var array<int, array<string, mixed>> */
	private array $content = [];

	/** @param \Closure(Player, array): void $onSubmit */
	public function __construct(
		private string $title,
		private \Closure $onSubmit
	){}

	public function addInput(string $text, string $placeholder = "", string $default = "") : self{
		$this->content[] = [
			"type" => "input",
			"text" => $text,
			"placeholder" => $placeholder,
			"default" => $default
		];

		return $this;
	}

	public function addLabel(string $text) : self{
		$this->content[] = [
			"type" => "label",
			"text" => $text
		];

		return $this;
	}

	public function handleResponse(Player $player, mixed $data) : void{
		if(!is_array($data)){
			return;
		}

		($this->onSubmit)($player, $data);
	}

	public function jsonSerialize() : mixed{
		return [
			"type" => "custom_form",
			"title" => $this->title,
			"content" => $this->content
		];
	}
}