<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Tests\Unit\Service;

use OCA\OpenRouterConnector\Service\ChatMessageBuilder;
use OCA\OpenRouterConnector\Tests\Unit\TestCase;
use OCP\Files\File;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;

class ChatMessageBuilderTest extends TestCase {
	private ChatMessageBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->builder = new ChatMessageBuilder($this->createL10n());
	}

	public function testSystemAndUserPrompt(): void {
		$messages = $this->builder->build('Be brief.', 'Hello');
		$this->assertSame([
			['role' => 'system', 'content' => 'Be brief.'],
			['role' => 'user', 'content' => 'Hello'],
		], $messages);
	}

	public function testEmptySystemPromptIsOmitted(): void {
		$this->assertSame([['role' => 'user', 'content' => 'Hello']], $this->builder->build('', 'Hello'));
	}

	public function testHistoryIsConverted(): void {
		$history = [
			json_encode(['role' => 'human', 'content' => 'Hi']),
			json_encode(['role' => 'assistant', 'content' => 'Hello, how can I help?']),
			json_encode(['role' => 'human', 'content' => [['type' => 'text', 'text' => 'Look at this'], ['type' => 'file', 'file_id' => 12]]]),
		];
		$messages = $this->builder->build(null, 'Thanks', $history);
		$this->assertSame([
			['role' => 'user', 'content' => 'Hi'],
			['role' => 'assistant', 'content' => 'Hello, how can I help?'],
			['role' => 'user', 'content' => 'Look at this'],
			['role' => 'user', 'content' => 'Thanks'],
		], $messages);
	}

	public function testAssistantToolCallsInHistoryAreConverted(): void {
		$history = [
			json_encode(['role' => 'assistant', 'content' => '', 'tool_calls' => [
				['id' => 'call_1', 'name' => 'get_weather', 'args' => ['city' => 'Berlin']],
				['id' => 'call_2', 'name' => 'noop', 'args' => []],
			]]),
			json_encode(['role' => 'tool', 'content' => 'sunny', 'tool_call_id' => 'call_1']),
		];
		$messages = $this->builder->build(null, null, $history, json_encode([['tool_call_id' => 'call_2', 'content' => ['ok' => true]]]));
		$this->assertSame([
			[
				'role' => 'assistant',
				'content' => null,
				'tool_calls' => [
					['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}']],
					['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'noop', 'arguments' => '{}']],
				],
			],
			['role' => 'tool', 'content' => 'sunny', 'tool_call_id' => 'call_1'],
			['role' => 'tool', 'content' => '{"ok":true}', 'tool_call_id' => 'call_2'],
		], $messages);
	}

	public function testFilePartsAreAttachedToTheUserMessage(): void {
		$messages = $this->builder->build('sys', 'Describe', null, null, [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']]]);
		$this->assertSame('user', $messages[1]['role']);
		$this->assertSame([
			['type' => 'text', 'text' => 'Describe'],
			['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
		], $messages[1]['content']);
	}

	public function testInvalidHistoryEntryIsRejected(): void {
		$this->expectException(UserFacingProcessingException::class);
		$this->builder->build(null, 'Hi', ['not json']);
	}

	public function testEmptyPromptIsRejected(): void {
		$this->expectException(UserFacingProcessingException::class);
		$this->builder->build(null, null);
	}

	public function testImageFilePart(): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('image/png');
		$file->method('getContent')->willReturn('png-bytes');
		$file->method('getName')->willReturn('photo.png');
		$this->assertSame([
			'type' => 'image_url',
			'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('png-bytes')],
		], $this->builder->buildFilePart($file));
	}

	public function testPdfFilePart(): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getContent')->willReturn('%PDF');
		$file->method('getName')->willReturn('doc.pdf');
		$part = $this->builder->buildFilePart($file);
		$this->assertSame('file', $part['type']);
		$this->assertSame('doc.pdf', $part['file']['filename']);
		$this->assertSame('data:application/pdf;base64,' . base64_encode('%PDF'), $part['file']['file_data']);
	}

	public function testUnsupportedFileTypeIsRejected(): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('text/plain');
		$file->method('getContent')->willReturn('hello');
		$file->method('getName')->willReturn('a.txt');
		$this->expectException(UserFacingProcessingException::class);
		$this->builder->buildFilePart($file);
	}
}
