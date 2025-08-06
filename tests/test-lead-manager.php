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

    public function test_send_lead_to_webhook_global_fallback() {
        update_option( 'aicp_settings', [ 'lead_webhook_url' => 'https://example.com' ] );

        $assistant_id = $this->factory->post->create( [ 'post_type' => 'aicp_assistant' ] );
        update_post_meta( $assistant_id, '_aicp_assistant_settings', [] );

        $lead_data = [ 'email' => 'test@example.com' ];
        $log_id    = 10;
        $status    = 'complete';

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

    public function test_send_lead_to_webhook_assistant_override() {
        update_option( 'aicp_settings', [ 'lead_webhook_url' => 'https://global.com' ] );

        $assistant_id = $this->factory->post->create( [ 'post_type' => 'aicp_assistant' ] );
        update_post_meta( $assistant_id, '_aicp_assistant_settings', [ 'webhook_url' => 'https://assistant.com' ] );

        $lead_data = [ 'email' => 'test@example.com' ];
        $log_id    = 20;
        $status    = 'complete';

        $captured = [];
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
            $captured['url']  = $url;
            $captured['body'] = $args['body'];
            return [ 'body' => '', 'headers' => [], 'response' => [ 'code' => 200 ] ];
        }, 10, 3 );

        AICP_Lead_Manager::send_lead_to_webhook( $lead_data, $assistant_id, $log_id, $status );

        remove_all_filters( 'pre_http_request' );

        $this->assertSame( 'https://assistant.com', $captured['url'] );
    }

    public function test_send_lead_to_webhook_button_status() {
        update_option( 'aicp_settings', [ 'lead_webhook_url' => 'https://example.com' ] );

        $assistant_id = $this->factory->post->create( [ 'post_type' => 'aicp_assistant' ] );
        update_post_meta( $assistant_id, '_aicp_assistant_settings', [] );

        $lead_data = [ 'email' => 'btn@example.com' ];
        $log_id    = 30;
        $status    = 'button';

        $captured = [];
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
            $captured['url']  = $url;
            $captured['body'] = $args['body'];
            return [ 'body' => '', 'headers' => [], 'response' => [ 'code' => 200 ] ];
        }, 10, 3 );

        AICP_Lead_Manager::send_lead_to_webhook( $lead_data, $assistant_id, $log_id, $status );

        remove_all_filters( 'pre_http_request' );

        $payload = json_decode( $captured['body'], true );
        $this->assertSame( $status, $payload['lead_status'] );
    }

    public function test_save_meta_box_sanitizes_lead_action_messages() {
        $user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );

        $assistant_id = $this->factory->post->create( [ 'post_type' => 'aicp_assistant' ] );

        $_POST['aicp_meta_box_nonce'] = wp_create_nonce( 'aicp_save_meta_box_data' );
        $_POST['aicp_settings'] = [
            'lead_action_messages' => [
                ' <b>Hello</b> ',
                'Good <script>alert("x")</script> '
            ],
        ];

        aicp_save_meta_box_data( $assistant_id );

        $settings = get_post_meta( $assistant_id, '_aicp_assistant_settings', true );
        $expected = array_map( 'sanitize_text_field', [ ' <b>Hello</b> ', 'Good <script>alert("x")</script> ' ] );
        $this->assertSame( $expected, $settings['lead_action_messages'] );

        $_POST = [];
    }

    public function test_save_meta_box_sanitizes_lead_email() {
        $user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );

        $assistant_id = $this->factory->post->create( [ 'post_type' => 'aicp_assistant' ] );

        $_POST['aicp_meta_box_nonce'] = wp_create_nonce( 'aicp_save_meta_box_data' );
        $_POST['aicp_settings'] = [ 'lead_email' => '  Lead@Example.com  ' ];

        aicp_save_meta_box_data( $assistant_id );

        $settings = get_post_meta( $assistant_id, '_aicp_assistant_settings', true );
        $this->assertSame( 'Lead@example.com', $settings['lead_email'] );

        $_POST = [];
    }

    public function test_email_lead_notification_uses_saved_or_admin_email() {
        $assistant_id = $this->factory->post->create( [ 'post_type' => 'aicp_assistant' ] );
        $lead_data    = [ 'email' => 'lead@example.com' ];

        update_post_meta( $assistant_id, '_aicp_assistant_settings', [ 'lead_email' => 'notify@example.com' ] );

        $captured = [];
        add_filter( 'wp_mail', function ( $args ) use ( &$captured ) {
            $captured[] = $args;
            return $args;
        } );

        AICP_Lead_Manager::email_lead_notification( $lead_data, $assistant_id, 1, 'complete' );

        remove_all_filters( 'wp_mail' );

        $this->assertSame( 'notify@example.com', $captured[0]['to'] );

        update_post_meta( $assistant_id, '_aicp_assistant_settings', [] );
        update_option( 'admin_email', 'admin@example.com' );

        add_filter( 'wp_mail', function ( $args ) use ( &$captured ) {
            $captured[] = $args;
            return $args;
        } );

        AICP_Lead_Manager::email_lead_notification( $lead_data, $assistant_id, 1, 'complete' );

        remove_all_filters( 'wp_mail' );

        $this->assertSame( 'admin@example.com', $captured[1]['to'] );
    }
}
