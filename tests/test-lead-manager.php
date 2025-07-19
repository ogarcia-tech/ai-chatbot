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
}
