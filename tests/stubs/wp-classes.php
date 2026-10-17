<?php
/**
 * Minimal stand-ins for WordPress core classes that WP_Mock does not provide.
 *
 * Only the members the framework actually touches are implemented.
 */

if (!class_exists('WP_Error')) {
	class WP_Error {
		public function __construct(
			public string $code = '',
			public string $message = '',
			public mixed $data = ''
		) {}

		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}

if (!class_exists('WP_User')) {
	class WP_User {
		public function __construct(
			public int $ID = 0,
			public array $roles = []
		) {}
	}
}

if (!class_exists('WP_REST_Server')) {
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
		public const EDITABLE  = 'POST, PUT, PATCH';
		public const DELETABLE = 'DELETE';
	}
}

if (!class_exists('WP_REST_Request')) {
	class WP_REST_Request {
		private ?array $json = null;

		public function __construct(
			public string $method = 'GET',
			public string $route = '',
			private array $params = []
		) {}

		public function set_body(string $body): void { $this->json = json_decode($body, true); }
		public function get_json_params(): ?array { return $this->json; }
		public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
		public function get_params(): array { return $this->params; }
	}
}

if (!class_exists('WP_REST_Response')) {
	class WP_REST_Response {
		public function __construct(
			private mixed $data = null,
			private int $status = 200
		) {}

		public function get_data(): mixed { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
}
