<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

final class VideoCommandRunner {
	/** @param list<string> $command
	 * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool}
	 */
	public function run(array $command, int $timeoutSeconds): array {
		if (!function_exists('proc_open')) return ['exitCode' => 127, 'stdout' => '', 'stderr' => 'process_unavailable', 'timedOut' => false];
		$pipes = [];
		$process = @proc_open($command, [
			0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
		], $pipes, options: ['bypass_shell' => true]);
		if (!is_resource($process)) return ['exitCode' => 127, 'stdout' => '', 'stderr' => 'process_unavailable', 'timedOut' => false];
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$stdout = '';
		$stderr = '';
		$deadline = microtime(true) + max(1, $timeoutSeconds);
		$timedOut = false;
		$exitCode = -1;
		do {
			$stdout = $this->append($stdout, stream_get_contents($pipes[1]));
			$stderr = $this->append($stderr, stream_get_contents($pipes[2]));
			$status = proc_get_status($process);
			if (!$status['running']) {
				$exitCode = (int)$status['exitcode'];
				break;
			}
			if (microtime(true) >= $deadline) {
				$timedOut = true;
				proc_terminate($process, 9);
				break;
			}
			usleep(50000);
		} while (true);
		$stdout = $this->append($stdout, stream_get_contents($pipes[1]));
		$stderr = $this->append($stderr, stream_get_contents($pipes[2]));
		fclose($pipes[1]);
		fclose($pipes[2]);
		$closed = proc_close($process);
		if ($exitCode < 0 && $closed >= 0) $exitCode = $closed;
		return ['exitCode' => $timedOut ? 124 : $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => $timedOut];
	}

	private function append(string $current, string|false $addition): string {
		if ($addition === false || $addition === '') return $current;
		return mb_substr($current . $addition, -65536);
	}
}
