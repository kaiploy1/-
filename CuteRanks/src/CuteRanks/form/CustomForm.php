<?php

declare(strict_types=1);

namespace CuteRanks\form;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;

final class CustomForm implements Form{

    private string $title = "";

    /** @var array<int, array<string, mixed>> */
    private array $content = [];

    public function __construct(
        private Closure $callback
    ){}

    public function setTitle(string $title): self{
        $this->title = $title;
        return $this;
    }

    public function addLabel(string $text): self{
        $this->content[] = [
            "type" => "label",
            "text" => $text
        ];
        return $this;
    }

    public function addInput(
        string $text,
        string $placeholder = "",
        string $default = ""
    ): self{
        $this->content[] = [
            "type" => "input",
            "text" => $text,
            "placeholder" => $placeholder,
            "default" => $default
        ];
        return $this;
    }

    public function addToggle(
        string $text,
        bool $default = false
    ): self{
        $this->content[] = [
            "type" => "toggle",
            "text" => $text,
            "default" => $default
        ];
        return $this;
    }

    /**
     * @param string[] $options
     */
    public function addDropdown(
        string $text,
        array $options,
        int $default = 0
    ): self{
        $this->content[] = [
            "type" => "dropdown",
            "text" => $text,
            "options" => array_values($options),
            "default" => $default
        ];
        return $this;
    }

    public function handleResponse(Player $player, mixed $data): void{
        if($data === null || !is_array($data)){
            return;
        }

        ($this->callback)($player, $data);
    }

    public function jsonSerialize(): array{
        return [
            "type" => "custom_form",
            "title" => $this->title,
            "content" => $this->content
        ];
    }
}