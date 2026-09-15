<?php

declare(strict_types=1);

namespace CuteRanks\chat;

use pocketmine\lang\Translatable;
use pocketmine\player\chat\ChatFormatter;
use pocketmine\utils\TextFormat;

final class RankChatFormatter implements ChatFormatter{

    /**
     * @param array<string, array<string, mixed>> $styles
     */
    public function __construct(
        private string $rankPrefix,
        private string $displayName,
        private string $separator,
        private string $styleId,
        private array $styles,
        private int $phase
    ){}

    public function format(
        string $username,
        string $message
    ): Translatable|string{
        $name = $this->applyStyle(
            $this->displayName,
            $this->styleId,
            $this->phase
        );

        $message = $this->applyStyle(
            $message,
            $this->styleId,
            $this->phase + 2
        );

        return $this->rankPrefix .
            " " .
            $name .
            $this->separator .
            $message;
    }

    private function applyStyle(
        string $text,
        string $styleId,
        int $phase
    ): string{
        $style = $this->styles[$styleId] ?? null;

        $code = is_array($style)
            ? (string) ($style["code"] ?? "§f")
            : "§f";

        if($code !== "rainbow"){
            return $code . TextFormat::clean($text);
        }

        $colors = [
            "§c",
            "§6",
            "§e",
            "§a",
            "§b",
            "§9",
            "§d",
            "§5"
        ];

        $characters = mb_str_split(TextFormat::clean($text));
        $result = "";

        foreach($characters as $index => $character){
            if($character === " "){
                $result .= " ";
                continue;
            }

            $result .=
                $colors[($index + $phase) % count($colors)] .
                $character;
        }

        return $result;
    }
}