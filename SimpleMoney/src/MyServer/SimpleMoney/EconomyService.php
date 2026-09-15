<?php

declare(strict_types=1);

namespace MyServer\SimpleMoney;

use MyServer\SimpleMoney\event\MoneyTransactionEvent;
use pocketmine\player\Player;

final class EconomyService{

    /** @var array<string, array{name: string, balance: float, createdAt: int, updatedAt: int}> */
    private array $accounts = [];

    /** @var array<string, string> */
    private array $nameIndex = [];

    private bool $dirty = false;

    public function __construct(
        private readonly string $dataFile,
        private readonly float $startingBalance
    ){
        $this->load();
    }

    public function ensureAccount(Player $player) : bool{
        return $this->createAccount(
            $player->getUniqueId()->toString(),
            $player->getName(),
            $this->startingBalance
        );
    }

    public function createAccount(string $uuid, string $name, float $openingBalance = 0.0) : bool{
        $uuid = trim($uuid);
        $name = trim($name);

        if($uuid === "" || $name === ""){
            throw new \InvalidArgumentException("UUID หรือชื่อผู้เล่นว่าง");
        }

        if(!is_finite($openingBalance) || $openingBalance < 0){
            throw new \InvalidArgumentException("ยอดเงินเริ่มต้นไม่ถูกต้อง");
        }

        if(isset($this->accounts[$uuid])){
            $this->updateName($uuid, $name);
            return false;
        }

        $now = time();
        $this->accounts[$uuid] = [
            "name" => $name,
            "balance" => round($openingBalance, 2),
            "createdAt" => $now,
            "updatedAt" => $now
        ];

        $this->nameIndex[strtolower($name)] = $uuid;
        $this->dirty = true;

        return true;
    }

    public function hasAccount(Player|string $playerOrUuid) : bool{
        try{
            $this->resolveAccountUuid($playerOrUuid);
            return true;
        }catch(\Throwable){
            return false;
        }
    }

    public function resolveAccountUuid(Player|string $playerOrUuid) : string{
        if($playerOrUuid instanceof Player){
            $this->ensureAccount($playerOrUuid);
            return $playerOrUuid->getUniqueId()->toString();
        }

        $value = trim($playerOrUuid);
        if(isset($this->accounts[$value])){
            return $value;
        }

        $uuid = $this->nameIndex[strtolower($value)] ?? null;
        if($uuid === null){
            throw new \InvalidArgumentException("ไม่พบบัญชีผู้เล่น: " . $value);
        }

        return $uuid;
    }

    public function getMoney(Player|string $playerOrUuid) : float{
        $uuid = $this->resolveAccountUuid($playerOrUuid);
        return $this->accounts[$uuid]["balance"];
    }

    public function setMoney(Player|string $playerOrUuid, float $amount, string $reason = "set") : bool{
        if(!is_finite($amount) || $amount < 0){
            return false;
        }

        $uuid = $this->resolveAccountUuid($playerOrUuid);
        $event = new MoneyTransactionEvent("set", null, $uuid, round($amount, 2), $reason);
        $event->call();

        if($event->isCancelled()){
            return false;
        }

        $this->accounts[$uuid]["balance"] = round($event->getAmount(), 2);
        $this->accounts[$uuid]["updatedAt"] = time();
        $this->dirty = true;
        return true;
    }

    public function deposit(Player|string $playerOrUuid, float $amount, string $reason = "deposit") : bool{
        if(!is_finite($amount) || $amount <= 0){
            return false;
        }

        $uuid = $this->resolveAccountUuid($playerOrUuid);
        $event = new MoneyTransactionEvent("deposit", null, $uuid, round($amount, 2), $reason);
        $event->call();

        if($event->isCancelled() || $event->getAmount() <= 0){
            return false;
        }

        $total = $this->accounts[$uuid]["balance"] + $event->getAmount();
        if(!is_finite($total)){
            return false;
        }

        $this->accounts[$uuid]["balance"] = round($total, 2);
        $this->accounts[$uuid]["updatedAt"] = time();
        $this->dirty = true;
        return true;
    }

    public function depositAutoIncome(Player $player, float $amount) : bool{
        return $this->deposit($player, $amount, "auto-income");
    }

    public function withdraw(Player|string $playerOrUuid, float $amount, string $reason = "withdraw") : bool{
        if(!is_finite($amount) || $amount <= 0){
            return false;
        }

        $uuid = $this->resolveAccountUuid($playerOrUuid);
        $event = new MoneyTransactionEvent("withdraw", $uuid, null, round($amount, 2), $reason);
        $event->call();

        if($event->isCancelled() || $event->getAmount() <= 0){
            return false;
        }

        if($this->accounts[$uuid]["balance"] < $event->getAmount()){
            return false;
        }

        $this->accounts[$uuid]["balance"] = round($this->accounts[$uuid]["balance"] - $event->getAmount(), 2);
        $this->accounts[$uuid]["updatedAt"] = time();
        $this->dirty = true;
        return true;
    }

    public function transfer(Player|string $from, Player|string $to, float $amount, string $reason = "transfer") : bool{
        if(!is_finite($amount) || $amount <= 0){
            return false;
        }

        $fromUuid = $this->resolveAccountUuid($from);
        $toUuid = $this->resolveAccountUuid($to);

        if($fromUuid === $toUuid){
            return false;
        }

        $event = new MoneyTransactionEvent("transfer", $fromUuid, $toUuid, round($amount, 2), $reason);
        $event->call();

        if($event->isCancelled() || $event->getAmount() <= 0){
            return false;
        }

        if($this->accounts[$fromUuid]["balance"] < $event->getAmount()){
            return false;
        }

        $this->accounts[$fromUuid]["balance"] = round($this->accounts[$fromUuid]["balance"] - $event->getAmount(), 2);
        $this->accounts[$toUuid]["balance"] = round($this->accounts[$toUuid]["balance"] + $event->getAmount(), 2);

        $now = time();
        $this->accounts[$fromUuid]["updatedAt"] = $now;
        $this->accounts[$toUuid]["updatedAt"] = $now;
        $this->dirty = true;
        return true;
    }

    public function getTop(int $limit = 10) : array{
        $result = [];
        foreach($this->accounts as $uuid => $account){
            $result[] = ["uuid" => $uuid, "name" => $account["name"], "balance" => $account["balance"]];
        }
        usort($result, static fn(array $a, array $b) : int => $b["balance"] <=> $a["balance"]);
        return array_slice($result, 0, max(1, $limit));
    }

    public function getKnownUuidByName(string $name) : ?string{
        $name = trim($name);
        if($name === ""){
            return null;
        }

        return $this->nameIndex[strtolower($name)] ?? null;
    }

    public function getPlayerName(string $playerOrUuid) : string{
        $playerOrUuid = trim($playerOrUuid);

        if(isset($this->accounts[$playerOrUuid])){
            return $this->accounts[$playerOrUuid]["name"];
        }

        $uuid = $this->nameIndex[strtolower($playerOrUuid)] ?? null;
        if($uuid !== null && isset($this->accounts[$uuid])){
            return $this->accounts[$uuid]["name"];
        }

        return $playerOrUuid;
    }

    public function save() : void{
        if(!$this->dirty){
            return;
        }
        $json = json_encode(["version" => 1, "accounts" => $this->accounts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tmpFile = $this->dataFile . ".tmp";
        if(file_put_contents($tmpFile, $json, LOCK_EX) !== false){
            @rename($tmpFile, $this->dataFile);
            $this->dirty = false;
        }
    }

    private function updateName(string $uuid, string $name) : void{
        $oldName = $this->accounts[$uuid]["name"];
        if($oldName === $name){
            return;
        }
        unset($this->nameIndex[strtolower($oldName)]);
        $this->nameIndex[strtolower($name)] = $uuid;
        $this->accounts[$uuid]["name"] = $name;
        $this->accounts[$uuid]["updatedAt"] = time();
        $this->dirty = true;
    }

    private function load() : void{
        if(!is_file($this->dataFile)){
            $this->dirty = true;
            $this->save();
            return;
        }
        try{
            $raw = file_get_contents($this->dataFile);
            if($raw === false) throw new \RuntimeException("Read error");
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            foreach(($data["accounts"] ?? []) as $uuid => $account){
                if(!is_string($uuid) || !is_array($account)) continue;
                $this->accounts[$uuid] = [
                    "name" => trim((string) ($account["name"] ?? "")),
                    "balance" => round((float) ($account["balance"] ?? 0), 2),
                    "createdAt" => (int) ($account["createdAt"] ?? time()),
                    "updatedAt" => (int) ($account["updatedAt"] ?? time())
                ];
                $this->nameIndex[strtolower($this->accounts[$uuid]["name"])] = $uuid;
            }
        }catch(\Throwable){
            $this->accounts = [];
            $this->nameIndex = [];
        }
    }
}