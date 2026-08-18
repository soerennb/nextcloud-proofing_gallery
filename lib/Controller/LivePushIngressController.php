<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\UploadBusyException;
use OCA\ProofingGallery\Service\LivePushService;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class LivePushIngressController extends Controller {
	public function __construct(IRequest $request, private LivePushService $livePush, private PolicyService $policies) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 3600)]
	#[BruteForceProtection(action: 'proofing_gallery_live_push')]
	#[FrontpageRoute(verb: 'PUT', url: '/live-push/upload')]
	public function upload(string $filename): JSONResponse {
		try {
			$context = $this->livePush->authenticate($this->request->getHeader('Authorization'));
			$temporaryPath = $this->receiveBody();
			try {
				$size = filesize($temporaryPath);
				if ($size === false) throw new \RuntimeException('Live Push upload size could not be determined');
				return new JSONResponse($this->livePush->upload($context['credential'], $context['gallery'], $filename, $temporaryPath, $size), Http::STATUS_CREATED);
			} finally {
				@unlink($temporaryPath);
			}
		} catch (\UnexpectedValueException|DoesNotExistException) {
			return new JSONResponse(['message' => 'Invalid Live Push credentials'], Http::STATUS_UNAUTHORIZED, ['WWW-Authenticate' => 'Basic realm="Proofing Gallery Live Push"']);
		} catch (UploadBusyException|\OCP\Lock\LockedException $exception) {
			return new JSONResponse(['code' => 'upload_busy', 'message' => $exception->getMessage()], Http::STATUS_LOCKED, ['Retry-After' => '1']);
		} catch (\InvalidArgumentException|FolderAccessException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function receiveBody(): string {
		$input = fopen('php://input', 'rb');
		$temporaryPath = tempnam(sys_get_temp_dir(), 'proofing-live-');
		if ($input === false || $temporaryPath === false) throw new \RuntimeException('Live Push upload could not be opened');
		$output = fopen($temporaryPath, 'wb');
		if ($output === false) {
			@unlink($temporaryPath);
			throw new \RuntimeException('Live Push upload could not be opened');
		}
		try {
			$bytes = stream_copy_to_stream($input, $output, $this->policies->get('maxUploadBytes') + 1);
			if ($bytes === false || $bytes > $this->policies->get('maxUploadBytes')) throw new \InvalidArgumentException('Upload size is outside the allowed range');
		} catch (\Throwable $exception) {
			@unlink($temporaryPath);
			throw $exception;
		} finally {
			fclose($input);
			fclose($output);
		}
		return $temporaryPath;
	}
}
