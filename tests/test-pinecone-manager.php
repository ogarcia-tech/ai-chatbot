<?php
class Pinecone_Manager_Test extends WP_UnitTestCase {
    private function invoke_chunk_content( $text, $token_limit ) {
        $method = new ReflectionMethod( AICP_Pinecone_Manager::class, 'chunk_content' );
        $method->setAccessible( true );
        return $method->invoke( null, $text, $token_limit );
    }

    public function test_chunk_content_handles_multibyte_characters() {
        $text   = 'áéíóú';
        $chunks = $this->invoke_chunk_content( $text, 1 ); // ~4 chars

        $this->assertSame( [ 'áéíó', 'ú' ], $chunks );
        $this->assertLessThanOrEqual( 4, mb_strlen( $chunks[0], 'UTF-8' ) );
    }
}
