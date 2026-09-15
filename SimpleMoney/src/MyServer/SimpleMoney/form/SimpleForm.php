<?php
// src/MyServer/SimpleMoney/form/SimpleForm.php

declare(strict_types=1);

namespace MyServer\SimpleMoney\form;

use pocketmine\form\Form;
use pocketmine\player\Player;

final class SimpleForm implements Form{

	/** @var array<int, array<string, mixed>> */
	private array $buttons = [];

	/** @param \Closure(Player, int): void $onSubmit */
	public function __construct(
		private string $title,
		private string $content,
		private \Closure $onSubmit
	){}

	public function addButton(string $text, ?string $imagePath = null) : self{
		$button = ["text" => $text];

		if($imagePath !== null && $imagePath !== ""){
			$button["image"] = [
				"type" => "path",
				"data" => $imagePath
			];
		}

		$this->buttons[] = $button;
		return $this;
	}

	public function handleResponse(Player $player, mixed $data) : void{
		if($data === null || !is_numeric($data)){
			return;
		}

		$index = (int) $data;

		if(!isset($this->buttons[$index])){
			return;
		}

		($this->onSubmit)($player, $index);
	}

	public function jsonSerialize() : mixed{
		return [
			"type" => "form",
			"title" => $this->title,
			"content" => $this->content,
			"buttons" => $this->buttons
		];
	}
}