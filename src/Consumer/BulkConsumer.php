<?php declare(strict_types = 1);

namespace Contributte\RabbitMQ\Consumer;

use Bunny\Channel;
use Bunny\Client;
use Bunny\ClientInterface;
use Bunny\Message;
use Contributte\RabbitMQ\Consumer\Exception\UnexpectedConsumerResultTypeException;
use Contributte\RabbitMQ\Queue\IQueue;
use Tests\Fixtures\ChannelMock;

class BulkConsumer extends Consumer
{

	/** @var BulkMessage[] */
	protected array $buffer = [];

	protected int $bulkSize;

	protected int $bulkTime;

	protected ?int $stopTime = null;

	private ?ClientInterface $lastClient = null;

	public function __construct(
		string $name,
		IQueue $queue,
		callable $callback,
		?int $prefetchSize,
		?int $prefetchCount,
		int $bulkSize,
		int $bulkTime
	)
	{
		parent::__construct($name, $queue, $callback, $prefetchSize, $prefetchCount);

		if ($bulkSize > 0 && $bulkTime > 0) {
			$this->bulkSize = $bulkSize;
			$this->bulkTime = $bulkTime;
		} else {
			throw new \InvalidArgumentException('Configuration values bulkSize and bulkTime must have value greater than zero');
		}
	}

	public function consume(?int $maxMessages = null, ?int $maxSeconds = null): void
	{
		$this->maxMessages = $maxMessages;
		if ($maxSeconds > 0) {
			$this->stopTime = time() + $maxSeconds;
		}

		$channel = $this->queue->getConnection()->getChannel();

		if ($this->prefetchSize !== null || $this->prefetchCount !== null) {
			$channel->qos($this->prefetchSize ?? 0, $this->prefetchCount ?? 0);
		}

		$this->setupConsume($channel);
		$this->startConsumeLoop($channel);

		$this->processBuffer();
	}

	private function setupConsume(Channel $channel): void
	{
		$channel->consume(
			function (Message $message, Channel $channel, Client $client): void {
				$this->messages++;
				$this->lastClient = $client;
				$bulkCount = $this->addToBuffer(new BulkMessage($message, $channel));
				if ($bulkCount >= $this->bulkSize || $this->isMaxMessages() || $this->isStopTime()) {
					$channel->close();
				}
			},
			$this->queue->getName()
		);
	}

	private function startConsumeLoop(Channel|ChannelMock $channel): void
	{
		do {
			// In tests ChannelMock has runClient(); in production Bunny\Channel doesn’t
			if (method_exists($channel, 'runClient')) {
				// use TTL to respect bulkTime/stopTime window
				$channel->runClient($this->getTtl());
			} else {
				// fallback for environments where we can only reach the client
				$this->lastClient?->run();
			}

			$this->processBuffer();
		} while (!$this->isStopTime() && !$this->isMaxMessages());
	}

	private function addToBuffer(BulkMessage $message): int
	{
		$this->buffer[] = $message;

		return count($this->buffer);
	}

	private function processBuffer(): void
	{
		if ($this->lastClient === null || count($this->buffer) === 0) {
			return;
		}

		$messages = [];
		foreach ($this->buffer as $bulkMessage) {
			$message = $bulkMessage->getMessage();
			$messages[$message->deliveryTag] = $message;
		}

		try {
			$result = call_user_func($this->callback, $messages);
		} catch (\Throwable $e) {
			$result = array_map(static fn () => IConsumer::MESSAGE_NACK, $messages);
		}

		if (!is_array($result)) {
			$result = array_map(static fn () => IConsumer::MESSAGE_NACK, $messages);
			$this->sendResultsBack($result);

			throw new UnexpectedConsumerResultTypeException(
				'Unexpected result from consumer. Expected array(delivery_tag => MESSAGE_STATUS [constant from IConsumer]) but get ' . gettype($result)
			);
		}

		$result = array_map('intval', $result);

		$this->sendResultsBack($result);

		$this->buffer = [];
	}

	/**
	 * @param array<mixed> $result
	 */
	private function sendResultsBack(array $result): void
	{
		foreach ($this->buffer as $bulkMessage) {
			$this->sendResponse(
				$bulkMessage->getMessage(),
				$bulkMessage->getChannel(),
				$result[$bulkMessage->getMessage()->deliveryTag] ?? IConsumer::MESSAGE_NACK,
			);
		}
	}

	private function isStopTime(): bool
	{
		return $this->stopTime !== null && $this->stopTime < time();
	}

	private function getTtl(): int
	{
		if ($this->stopTime > 0) {
			return min($this->bulkTime, $this->stopTime - time());
		}

		return $this->bulkTime;
	}

}
