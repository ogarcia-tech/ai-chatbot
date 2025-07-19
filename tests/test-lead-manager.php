<?php
class Lead_Manager_Test extends WP_UnitTestCase {
    public function test_detect_contact_data_complete_conversation() {
        $conversation = [
            ['role' => 'user', 'content' => 'Hola, me llamo Juan Pérez.'],
            ['role' => 'assistant', 'content' => 'Encantado de conocerte, Juan.'],
            ['role' => 'user', 'content' => 'Mi email es juan@example.com y mi teléfono es 912 345 678. Mi web es juanperez.com.'],
        ];

        $result = AICP_Lead_Manager::detect_contact_data( $conversation );

        $this->assertTrue( $result['has_lead'] );
        $this->assertTrue( $result['is_complete'] );
        $this->assertEquals( 'Juan Pérez', $result['data']['name'] );
        $this->assertEquals( 'juan@example.com', $result['data']['email'] );
        $this->assertEquals( 'http://juanperez.com', $result['data']['website'] );
        $this->assertArrayHasKey( 'phone', $result['data'] );
        $this->assertEmpty( $result['missing_fields'] );
    }

    public function test_send_lead_to_webhook() {
        update_option( 'aicp_settings', [ 'lead_webhook_url' => 'https://example.com' ] );

        $lead_data    = [ 'email' => 'test@example.com' ];
        $assistant_id = 5;
        $log_id       = 10;
        $status       = 'complete';

        $captured = [];
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
            $captured['url']  = $url;
            $captured['body'] = $args['body'];
            return [ 'body' => '', 'headers' => [], 'response' => [ 'code' => 200 ] ];
        }, 10, 3 );

        AICP_Lead_Manager::send_lead_to_webhook( $lead_data, $assistant_id, $log_id, $status );

        remove_all_filters( 'pre_http_request' );

        $this->assertSame( 'https://example.com', $captured['url'] );
        $payload = json_decode( $captured['body'], true );
        $this->assertSame( $lead_data, $payload['lead_data'] );
        $this->assertSame( $assistant_id, $payload['assistant_id'] );
        $this->assertSame( $log_id, $payload['log_id'] );
        $this->assertSame( $status, $payload['lead_status'] );
    }
}
