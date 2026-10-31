<?php

declare(strict_types=1);

namespace Tests\Performance;

/**
 * Reads a phpbench XML dump (`--dump-file`) and answers the one question the
 * scale benchmarks exist for: does anything grow faster than the collection?
 */
final class PerfReport
{
	/** @param list<array{bench:string,subject:string,size:string,time_ms:float,mem_peak_mb:float}> $rows */
	private function __construct(private readonly array $rows)
	{
	}

	public static function fromXml(string $xml): self
	{
		$doc = new \SimpleXMLElement($xml);

		$rows = [];
		foreach ($doc->xpath('//benchmark') ?: [] as $benchmark) {
			$parts = explode('\\', (string)$benchmark['class']);
			$bench = end($parts);
			foreach ($benchmark->subject as $subject) {
				foreach ($subject->variant as $variant) {
					$memPeak = 0;
					foreach ($variant->iteration as $iteration) {
						$memPeak = max($memPeak, (int)$iteration['mem-peak']);
					}
					$rows[] = [
						'bench'       => $bench,
						'subject'     => (string)$subject['name'],
						'size'        => (string)$variant->{'parameter-set'}['name'],
						'time_ms'     => (float)$variant->stats['mode'] / 1000,
						'mem_peak_mb' => $memPeak / 1048576,
					];
				}
			}
		}

		return new self($rows);
	}

	public function toCsv(): string
	{
		$csv = "bench,subject,size,time_ms,mem_peak_mb\n";
		foreach ($this->rows as $row) {
			$csv .= sprintf("%s,%s,%s,%.3f,%.1f\n", $row['bench'], $row['subject'], $row['size'], $row['time_ms'], $row['mem_peak_mb']);
		}

		return $csv;
	}

	/**
	 * Steps between consecutive sizes where time or memory grew more than
	 * `$tolerance` times faster than the size did.
	 *
	 * @return list<array{subject:string,from:int,to:int,sizeRatio:float,timeRatio:float,memRatio:float}>
	 */
	public function superlinear(float $tolerance = 1.5): array
	{
		$flags = [];
		foreach ($this->bySubject() as $subject => $rows) {
			usort($rows, fn (array $a, array $b): int => self::scale($a['size']) <=> self::scale($b['size']));

			for ($i = 1, $n = count($rows); $i < $n; $i++) {
				$from      = self::scale($rows[$i - 1]['size']);
				$to        = self::scale($rows[$i]['size']);
				$sizeRatio = $to / max(1, $from);
				$timeRatio = $rows[$i]['time_ms'] / max(0.001, $rows[$i - 1]['time_ms']);
				$memRatio  = $rows[$i]['mem_peak_mb'] / max(0.001, $rows[$i - 1]['mem_peak_mb']);

				if ($timeRatio > $sizeRatio * $tolerance || $memRatio > $sizeRatio * $tolerance) {
					$flags[] = [
						'subject'   => $subject,
						'from'      => $from,
						'to'        => $to,
						'sizeRatio' => round($sizeRatio, 2),
						'timeRatio' => round($timeRatio, 2),
						'memRatio'  => round($memRatio, 2),
					];
				}
			}
		}

		return $flags;
	}

	/**
	 * Rows whose request peak would not fit under a host's memory_limit.
	 *
	 * @return list<array{bench:string,subject:string,size:string,time_ms:float,mem_peak_mb:float}>
	 */
	public function overMemoryLimit(int $limitMb): array
	{
		return array_values(array_filter($this->rows, fn (array $row): bool => $row['mem_peak_mb'] > $limitMb));
	}

	/** @return array<string,list<array{bench:string,subject:string,size:string,time_ms:float,mem_peak_mb:float}>> */
	private function bySubject(): array
	{
		$grouped = [];
		foreach ($this->rows as $row) {
			$grouped[$row['bench'] . '::' . $row['subject']][] = $row;
		}

		return $grouped;
	}

	/** "50000" → 50000; router sets "1000-p500" scale with their page count → 500. */
	private static function scale(string $set): int
	{
		return preg_match('/-p(\d+)$/', $set, $m) === 1 ? (int)$m[1] : (int)$set;
	}
}
