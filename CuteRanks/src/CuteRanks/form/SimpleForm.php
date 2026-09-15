<?php

declare(strict_types=1);

namespace CuteRanks\form;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;

final class SimpleForm implements Form{

    private string $title = "";
    private string $content = "";

    /** @var array<int, array<string, mixed>> */
    private array $buttons = [];

    public function __construct(
        private Closure $callback
    ){}

    public function setTitle(string $title): self{
        $this->title = $title;
        return $this;
    }

    public function setContent(string $content): self{
        $this->content = $content;
        return $this;
    }

    public function addButton(
        string $text,
        ?string $imageType = null,
        ?string $imagePath = null
    ): self{
        $button = ["text" => $text];

        if($imageType !== null && $imagePath !== null){
            $button["image"] = [
                "type" => $imageType,
                "data" => $imagePath
            ];
        }

        $this->buttons[] = $button;
        return $this;
    }

    public function handleResponse(Player $player, mixed $data): void{
        if($data === null){
            return;
        }

        if(!is_int($data) && !is_float($data)){
            return;
        }

        ($this->callback)($player, (int) $data);
    }

    public function jsonSerialize(): array{
        return [
            "type" => "form",
            "title" => $this->title,
            "content" => $this->content,
            "buttons" => $this->buttons
        ];
    }
}