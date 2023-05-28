<?php

namespace platz1de\BulkLayerEditor;

use InvalidArgumentException;
use pocketmine\event\Listener;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\plugin\DisablePluginException;
use pocketmine\plugin\PluginBase;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use RuntimeException;

class BulkLayerEditor extends PluginBase implements Listener
{
	private const MODE_CLEAR = 0;

	private const WORLD_MODE_AUTO = 0;
	private const WORLD_MODE_ALL = 1;
	private const WORLD_MODE_SPECIFIC = 2;

	private int $mode;
	private int $layer;
	private int $worldMode;
	/**
	 * @var string[]
	 */
	private array $worlds = [];

	public function onEnable(): void
	{
		$config = $this->getConfig();
		if (!$config->get("confirm")) {
			// Only send warning if plugin wasn't used before
			if (!file_exists($this->getDataFolder() . ".idle")) {
				$this->getLogger()->error("Please read the config.yml on how to use this plugin!");
				throw new DisablePluginException();
			}
			return;
		}
		file_put_contents($this->getDataFolder() . ".idle", "");

		$this->mode = match (strtolower(trim($config->get("mode", "none")))) {
			"clear" => self::MODE_CLEAR,
			default => throw new InvalidArgumentException("Invalid mode '" . $config->get("mode") . "' specified, expected 'clear'"),
		};

		$this->layer = (int) $config->get("layer", 1);
		if ($this->layer < 0) {
			throw new InvalidArgumentException("Layer must be >= 0");
		}

		$this->worldMode = match (strtolower(trim($config->get("worlds", "auto")))) {
			"auto" => self::WORLD_MODE_AUTO,
			"all" => self::WORLD_MODE_ALL,
			default => self::WORLD_MODE_SPECIFIC
		};
		if ($this->worldMode === self::WORLD_MODE_SPECIFIC) {
			$this->worlds = explode(",", $config->get("worlds"));
		}

		if (!file_exists($concurrentDirectory = $this->getDataFolder() . "backups") && !mkdir($concurrentDirectory) && !is_dir($concurrentDirectory)) {
			throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
		}

		// Disable while keeping comments
		$conf = preg_split("/\r\n|\n|\r/", file_get_contents($this->getDataFolder() . "config.yml"));
		foreach ($conf as $i => $line) {
			if (str_starts_with($line, "confirm:")) {
				$conf[$i] = "confirm: false";
				break;
			}
		}
		file_put_contents($this->getDataFolder() . "config.yml", implode("\n", $conf));

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		if ($this->worldMode !== self::WORLD_MODE_AUTO) {
			foreach (scandir($this->getServer()->getDataPath() . "worlds") as $world) {
				if (($world === ".") || ($world === "..")) {
					continue;
				}
				if (($this->worldMode === self::WORLD_MODE_SPECIFIC) && !in_array($world, $this->worlds, true)) {
					continue;
				}
				$this->getServer()->getWorldManager()->loadWorld($world);
			}
		}
	}

	public function handleWorldLoad(WorldLoadEvent $event): void
	{
		if (($this->worldMode === self::WORLD_MODE_SPECIFIC) && !in_array($event->getWorld()->getFolderName(), $this->worlds, true)) {
			return;
		}
		$this->convertWorld($event->getWorld());
	}

	private function convertWorld(World $world): void
	{
		$backupName = $world->getFolderName() . "_" . date("Y-m-d_H-i-s");
		$this->getLogger()->info("Creating backup of world " . $world->getFolderName() . " as $backupName");
		//copy directory
		$this->recurseCopy($world->getProvider()->getPath(), $this->getDataFolder() . DIRECTORY_SEPARATOR . "backups" . DIRECTORY_SEPARATOR . $backupName);

		$start = microtime(true);
		$this->getLogger()->info("Starting conversion of world " . $world->getFolderName());

		$done = 0;
		$count = $world->getProvider()->calculateChunkCount();
		$last = $start;
		foreach ($world->getProvider()->getAllChunks(true, $this->getLogger()) as $pos => $chunk) {
			[$x, $z] = $pos;
			$chunk->getChunk()->setTerrainDirty();
			$this->convertChunk($chunk->getChunk());
			$world->getProvider()->saveChunk($x, $z, $chunk);
			$done++;
			if (($done % 1000) === 0) {
				$time = microtime(true);
				$diff = $time - $last;
				$last = $time;
				$this->getLogger()->info("Converted $done / $count chunks (" . floor(1000 / $diff) . " chunks/sec)");
			}
		}
		$total = microtime(true) - $start;
		$this->getLogger()->info("Converted $done / $done chunks in " . round($total, 3) . " seconds (" . floor($done / $total) . " chunks/sec)");
	}

	private function convertChunk(Chunk $chunk): void
	{
		switch ($this->mode) {
			case self::MODE_CLEAR:
				foreach ($chunk->getSubChunks() as $subChunk) {
					(function ($l): void {
						/** @noinspection all */
						unset($this->blockLayers[$l]);
					})->call($subChunk, $this->layer);
				}
				break;
		}
	}

	private function recurseCopy(string $from, string $to): void
	{
		$dir = opendir($from);
		if (!mkdir($to) && !is_dir($to)) {
			throw new RuntimeException(sprintf('Directory "%s" was not created', $to));
		}
		while (false !== ($file = readdir($dir))) {
			if (($file !== ".") && ($file !== "..")) {
				if (is_dir($from . DIRECTORY_SEPARATOR . $file)) {
					$this->recurseCopy($from . DIRECTORY_SEPARATOR . $file, $to . DIRECTORY_SEPARATOR . $file);
				} else {
					copy($from . DIRECTORY_SEPARATOR . $file, $to . DIRECTORY_SEPARATOR . $file);
				}
			}
		}
		closedir($dir);
	}
}