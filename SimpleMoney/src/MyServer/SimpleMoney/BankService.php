<?php
// src/MyServer/SimpleMoney/BankService.php

declare(strict_types=1);

namespace MyServer\SimpleMoney;

use MyServer\SimpleMoney\event\BankTransactionEvent;
use pocketmine\player\Player;

final class BankService{

	/** @var array<string, array{balance: float, updatedAt: int}> */
	private array $accounts = [];

	private string $dataFile;

	private bool $dirty = false;

	public function __construct(
		private Main $plugin,
		private EconomyService $economy
	){
		$this->dataFile = $this->plugin->getDataFolder() . "bank.json";
		$this->load();
	}

	public function ensureAccount(Player|string $playerOrUuid) : void{
		$uuid = $this->economy->resolveAccountUuid($playerOrUuid);

		if(!isset($this->accounts[$uuid])){
			$this->accounts[$uuid] = [
				"balance" => 0.0,
				"updatedAt" => time()
			];

			$this->dirty = true;
		}
	}

	public function getBalance(Player|string $playerOrUuid) : float{
		$this->ensureAccount($playerOrUuid);
		$uuid = $this->economy->resolveAccountUuid($playerOrUuid);

		return $this->accounts[$uuid]["balance"];
	}

	public function deposit(Player|string $playerOrUuid, float $amount, string $reason = "bank-deposit") : bool{
		if(!is_finite($amount) || $amount <= 0){
			return false;
		}

		$this->ensureAccount($playerOrUuid);
		$uuid = $this->economy->resolveAccountUuid($playerOrUuid);

		$event = new BankTransactionEvent("deposit", $uuid, round($amount, 2), $reason);
		$event->call();

		if($event->isCancelled() || $event->getAmount() <= 0){
			return false;
		}

		if(!$this->economy->withdraw($uuid, $event->getAmount(), "bank-deposit")){
			return false;
		}

		$this->accounts[$uuid]["balance"] = round(
			$this->accounts[$uuid]["balance"] + $event->getAmount(),
			2
		);
		$this->accounts[$uuid]["updatedAt"] = time();
		$this->dirty = true;

		return true;
	}

	public function withdraw(Player|string $playerOrUuid, float $amount, string $reason = "bank-withdraw") : bool{
		if(!is_finite($amount) || $amount <= 0){
			return false;
		}

		$this->ensureAccount($playerOrUuid);
		$uuid = $this->economy->resolveAccountUuid($playerOrUuid);

		$event = new BankTransactionEvent("withdraw", $uuid, round($amount, 2), $reason);
		$event->call();

		if($event->isCancelled() || $event->getAmount() <= 0){
			return false;
		}

		if($this->accounts[$uuid]["balance"] < $event->getAmount()){
			return false;
		}

		if(!$this->economy->deposit($uuid, $event->getAmount(), "bank-withdraw")){
			return false;
		}

		$this->accounts[$uuid]["balance"] = round(
			$this->accounts[$uuid]["balance"] - $event->getAmount(),
			2
		);
		$this->accounts[$uuid]["updatedAt"] = time();
		$this->dirty = true;

		return true;
	}

	/**
	 * เพิ่มดอกเบี้ยให้ทุกบัญชีธนาคาร
	 *
	 * @param float $ratePercent ตัวอย่าง 1.5 = ดอกเบี้ย 1.5%
	 * @return int จำนวนบัญชีที่ได้รับดอกเบี้ย
	 */
	public function applyInterest(float $ratePercent) : int{
		if(!is_finite($ratePercent) || $ratePercent <= 0){
			return 0;
		}

		$count = 0;

		foreach($this->accounts as $uuid => $account){
			if($account["balance"] <= 0){
				continue;
			}

			$interest = round($account["balance"] * ($ratePercent / 100), 2);

			if($interest <= 0){
				continue;
			}

			$event = new BankTransactionEvent("interest", $uuid, $interest, "scheduled-interest");
			$event->call();

			if($event->isCancelled() || $event->getAmount() <= 0){
				continue;
			}

			$this->accounts[$uuid]["balance"] = round(
				$this->accounts[$uuid]["balance"] + $event->getAmount(),
				2
			);
			$this->accounts[$uuid]["updatedAt"] = time();
			$count++;
		}

		if($count > 0){
			$this->dirty = true;
		}

		return $count;
	}

	/**
	 * @return array<int, array{uuid: string, name: string, balance: float}>
	 */
	public function getTop(int $limit = 10) : array{
		$result = [];

		foreach($this->accounts as $uuid => $account){
			try{
				$result[] = [
					"uuid" => $uuid,
					"name" => $this->economy->getPlayerName($uuid),
					"balance" => $account["balance"]
				];
			}catch(\Throwable){
				continue;
			}
		}

		usort($result, static function(array $a, array $b) : int{
			return $b["balance"] <=> $a["balance"];
		});

		return array_slice($result, 0, max(1, $limit));
	}

	public function save() : void{
		if(!$this->dirty){
			return;
		}

		$json = json_encode(
			["version" => 1, "accounts" => $this->accounts],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		);

		$tmp = $this->dataFile . ".tmp";
		file_put_contents($tmp, $json, LOCK_EX);

		if(!@rename($tmp, $this->dataFile)){
			@unlink($this->dataFile);
			@rename($tmp, $this->dataFile);
		}

		$this->dirty = false;
	}

	private function load() : void{
		if(!is_file($this->dataFile)){
			$this->dirty = true;
			$this->save();
			return;
		}

		try{
			$data = json_decode(
				(string) file_get_contents($this->dataFile),
				true,
				512,
				JSON_THROW_ON_ERROR
			);

			foreach(($data["accounts"] ?? []) as $uuid => $account){
				$balance = (float) ($account["balance"] ?? 0);

				if(is_finite($balance) && $balance >= 0){
					$this->accounts[$uuid] = [
						"balance" => round($balance, 2),
						"updatedAt" => (int) ($account["updatedAt"] ?? time())
					];
				}
			}
		}catch(\Throwable){
			$this->accounts = [];
			$this->dirty = true;
		}
	}
}