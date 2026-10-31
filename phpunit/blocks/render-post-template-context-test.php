<?php
/**
 * Tests context propagation through the Post Template block.
 *
 * @package WordPress
 * @subpackage Blocks
 * @group blocks
 */
class Tests_Blocks_RenderPostTemplateContext extends WP_UnitTestCase {

	private $post_ids;
	private $observations;
	private $filters;
	private $template_type;
	private $original_template_properties;

	public function set_up() {
		parent::set_up();
		$this->post_ids                     = self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		$this->observations                 = array();
		$this->filters                      = array();
		$this->template_type                = WP_Block_Type_Registry::get_instance()->get_registered( 'core/post-template' );
		$this->original_template_properties = array(
			'attributes'          => $this->template_type->attributes,
			'uses_context'        => $this->template_type->uses_context,
			'provides_context'    => $this->template_type->provides_context,
			'render_callback'     => $this->template_type->render_callback,
			'view_style_handles'  => $this->template_type->view_style_handles,
			'view_script_handles' => $this->template_type->view_script_handles,
		);

		register_block_type(
			'test/template-context-provider',
			array(
				'attributes'       => array(
					'value'    => array( 'type' => 'object' ),
					'marker'   => array( 'type' => 'string' ),
					'enabled'  => array( 'type' => 'boolean' ),
					'count'    => array( 'type' => 'integer' ),
					'secret'   => array( 'type' => 'string' ),
					'postId'   => array( 'type' => 'integer' ),
					'postType' => array( 'type' => 'string' ),
				),
				'provides_context' => array(
					'test/value'       => 'value',
					'test/marker'      => 'marker',
					'test/enabled'     => 'enabled',
					'test/count'       => 'count',
					'test/unrequested' => 'secret',
					'postId'           => 'postId',
					'postType'         => 'postType',
				),
				'render_callback'  => static function ( $attributes, $content ) {
					return $content;
				},
			)
		);
		register_block_type(
			'test/template-context-consumer',
			array(
				'attributes'      => array(
					'slot' => array(
						'type'    => 'string',
						'default' => 'leaf',
					),
				),
				'uses_context'    => array( 'test/value', 'test/marker', 'test/enabled', 'test/count', 'test/from-filter', 'test/template-value', 'postId', 'postType' ),
				'render_callback' => function ( $attributes, $content, $block ) {
					$this->observations[] = array(
						'slot'    => $attributes['slot'],
						'context' => $block->context,
						'post_id' => get_the_ID(),
					);
					return '<span>' . esc_html( $attributes['slot'] ) . '</span>';
				},
			)
		);
		$this->add_test_filter(
			'query_loop_block_query_vars',
			function ( $query, $block ) {
				if ( 68608 === ( $block->context['queryId'] ?? null ) ) {
					$query['post__in'] = $this->post_ids;
					$query['orderby']  = 'post__in';
				}
				return $query;
			}
		);
	}

	public function tear_down() {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		foreach ( $this->original_template_properties as $property => $value ) {
			$this->template_type->$property = $value;
		}
		unregister_block_type( 'test/template-context-provider' );
		unregister_block_type( 'test/template-context-consumer' );
		wp_reset_postdata();
		parent::tear_down();
	}

	private function add_test_filter( $name, $callback, $priority = 10 ) {
		add_filter( $name, $callback, $priority, 3 );
		$this->filters[] = array( $name, $callback, $priority );
	}

	private function block_markup( $name, $attributes = array(), $content = '' ) {
		return get_comment_delimited_block_content( $name, $attributes, $content );
	}

	private function consumer( $slot = 'leaf' ) {
		return $this->block_markup( 'test/template-context-consumer', array( 'slot' => $slot ) );
	}

	private function provider( $attributes, $content ) {
		return $this->block_markup( 'test/template-context-provider', $attributes, $content );
	}

	private function query( $content, $template_attributes = array(), $enhanced_pagination = false ) {
		return $this->block_markup(
			'core/query',
			array(
				'queryId'            => 68608,
				'enhancedPagination' => $enhanced_pagination,
				'query'              => array(
					'inherit'  => false,
					'perPage'  => 2,
					'postType' => 'post',
				),
			),
			$this->block_markup( 'core/post-template', $template_attributes, $content )
		);
	}

	private function assert_row_context( $name, $expected ) {
		$this->assertCount( 2, $this->observations );
		foreach ( $this->observations as $observation ) {
			$this->assertArrayHasKey( $name, $observation['context'] );
			$this->assertSame( $expected, $observation['context'][ $name ] );
		}
	}

	public function test_passes_ancestor_context_not_consumed_by_post_template() {
		$value = array(
			'label'  => 'From an ancestor',
			'nested' => array( 'enabled' => false ),
		);
		do_blocks( $this->provider( array( 'value' => $value ), $this->query( $this->consumer() ) ) );
		$this->assertNotContains( 'test/value', $this->template_type->uses_context );
		$this->assert_row_context( 'test/value', $value );
	}

	public function test_passes_ancestor_context_through_group() {
		$group = $this->block_markup( 'core/group', array(), '<div class="wp-block-group">' . $this->consumer() . '</div>' );
		do_blocks( $this->provider( array( 'marker' => 'Ancestor' ), $this->query( $group ) ) );
		$this->assert_row_context( 'test/marker', 'Ancestor' );
	}

	public function test_passes_context_explicitly_consumed_by_post_template() {
		$this->template_type->uses_context = array_merge( $this->template_type->uses_context, array( 'test/marker' ) );
		do_blocks( $this->provider( array( 'marker' => 'Declared' ), $this->query( $this->consumer() ) ) );
		$this->assert_row_context( 'test/marker', 'Declared' );
	}

	public function test_passes_context_added_to_post_template_by_filter() {
		$this->add_test_filter(
			'render_block_context',
			static function ( $context, $parsed_block ) {
				if ( 'core/post-template' === $parsed_block['blockName'] ) {
					$context['test/from-filter'] = 'Filtered';
				}
				return $context;
			}
		);
		do_blocks( $this->query( $this->consumer() ) );
		$this->assert_row_context( 'test/from-filter', 'Filtered' );
	}

	public function test_preserves_nearest_ancestor_provider() {
		$inner = $this->provider( array( 'marker' => 'Nearest' ), $this->query( $this->consumer() ) );
		do_blocks( $this->provider( array( 'marker' => 'Outer' ), $inner ) );
		$this->assert_row_context( 'test/marker', 'Nearest' );
	}

	public function test_inner_provider_overrides_context_without_affecting_sibling() {
		$children = $this->provider( array( 'marker' => 'Inner' ), $this->consumer( 'inner' ) ) . $this->consumer( 'sibling' );
		do_blocks( $this->provider( array( 'marker' => 'Outer' ), $this->query( $children ) ) );
		$this->assertCount( 4, $this->observations );
		foreach ( $this->observations as $observation ) {
			$this->assertArrayHasKey( 'test/marker', $observation['context'] );
			$this->assertSame( 'inner' === $observation['slot'] ? 'Inner' : 'Outer', $observation['context']['test/marker'] );
		}
	}

	public function test_preserves_false_and_zero_context_values() {
		do_blocks(
			$this->provider(
				array(
					'enabled' => false,
					'count'   => 0,
				),
				$this->query( $this->consumer() )
			)
		);
		$this->assert_row_context( 'test/enabled', false );
		$this->assert_row_context( 'test/count', 0 );
	}

	public function test_empty_inner_provider_value_overrides_ancestor_without_affecting_sibling() {
		$value    = array( 'token' => 'Outer' );
		$children = $this->provider( array( 'value' => array() ), $this->consumer( 'inner' ) ) . $this->consumer( 'sibling' );
		do_blocks( $this->provider( array( 'value' => $value ), $this->query( $children ) ) );
		$this->assertCount( 4, $this->observations );
		foreach ( $this->observations as $observation ) {
			$this->assertArrayHasKey( 'test/value', $observation['context'] );
			$this->assertSame( 'inner' === $observation['slot'] ? array() : $value, $observation['context']['test/value'] );
		}
	}

	public function test_preserves_context_provided_by_post_template_attribute() {
		$this->template_type->attributes['testValue']                 = array( 'type' => 'string' );
		$this->template_type->provides_context['test/template-value'] = 'testValue';
		do_blocks( $this->query( $this->consumer(), array( 'testValue' => 'Template' ) ) );
		$this->assert_row_context( 'test/template-value', 'Template' );
	}

	public function test_preserves_context_provided_by_post_template_default_attribute() {
		$this->template_type->attributes['testValue']                 = array(
			'type'    => 'string',
			'default' => 'Default',
		);
		$this->template_type->provides_context['test/template-value'] = 'testValue';
		do_blocks( $this->query( $this->consumer() ) );
		$this->assert_row_context( 'test/template-value', 'Default' );
	}

	public function test_queried_post_identity_overrides_ancestor_identity_on_each_row() {
		do_blocks(
			$this->provider(
				array(
					'postId'   => 999999,
					'postType' => 'page',
				),
				$this->query( $this->consumer() )
			)
		);
		$this->assertCount( 2, $this->observations );
		foreach ( $this->observations as $index => $observation ) {
			$this->assertSame( $this->post_ids[ $index ], $observation['post_id'] );
			$this->assertSame( $this->post_ids[ $index ], $observation['context']['postId'] );
			$this->assertSame( 'post', $observation['context']['postType'] );
		}
	}

	public function test_later_context_filters_receive_queried_post_identity() {
		$filtered_ids = array();
		$this->add_test_filter(
			'render_block_context',
			static function ( $context, $parsed_block ) use ( &$filtered_ids ) {
				if ( 'test/template-context-consumer' === $parsed_block['blockName'] ) {
					$filtered_ids[] = $context['postId'] ?? null;
				}
				return $context;
			}
		);
		do_blocks( $this->query( $this->consumer() ) );
		$this->assertSame( $this->post_ids, $filtered_ids );
	}

	public function test_row_specific_filtered_context_does_not_leak_into_next_row() {
		$first_post_id = $this->post_ids[0];
		$this->add_test_filter(
			'render_block_context',
			static function ( $context, $parsed_block ) use ( $first_post_id ) {
				if ( 'test/template-context-consumer' === $parsed_block['blockName'] && $first_post_id === $context['postId'] ) {
					$context['test/from-filter'] = 'First row only';
				}
				return $context;
			}
		);
		do_blocks( $this->query( $this->consumer() ) );
		$this->assertCount( 2, $this->observations );
		$this->assertSame( 'First row only', $this->observations[0]['context']['test/from-filter'] );
		$this->assertArrayNotHasKey( 'test/from-filter', $this->observations[1]['context'] );
	}

	public function test_separate_query_instances_do_not_share_context() {
		$content  = $this->provider( array( 'marker' => 'First' ), $this->query( $this->consumer( 'first' ) ) );
		$content .= $this->provider( array( 'marker' => 'Second' ), $this->query( $this->consumer( 'second' ) ) );
		do_blocks( $content );
		$this->assertCount( 4, $this->observations );
		foreach ( $this->observations as $observation ) {
			$this->assertArrayHasKey( 'test/marker', $observation['context'] );
			$this->assertSame( 'first' === $observation['slot'] ? 'First' : 'Second', $observation['context']['test/marker'] );
		}
	}

	public function test_context_and_post_identity_filter_do_not_leak_after_query() {
		do_blocks( $this->provider( array( 'marker' => 'Inside' ), $this->query( $this->consumer() ) ) . $this->consumer( 'outside' ) );
		$this->assertCount( 3, $this->observations );
		$outside = $this->observations[2]['context'];
		$this->assertArrayNotHasKey( 'test/marker', $outside );
		$this->assertArrayNotHasKey( 'postId', $outside );
		$this->assertArrayNotHasKey( 'postType', $outside );
	}

	public function test_consumer_context_is_projected_to_its_declared_keys() {
		do_blocks(
			$this->provider(
				array(
					'marker' => 'Requested',
					'secret' => 'Not requested',
				),
				$this->query( $this->consumer() )
			)
		);
		$this->assert_row_context( 'test/marker', 'Requested' );
		foreach ( $this->observations as $observation ) {
			$this->assertArrayNotHasKey( 'test/unrequested', $observation['context'] );
		}
	}

	public function test_rendering_same_instance_twice_does_not_mutate_source_or_rows() {
		$parsed   = parse_blocks( $this->provider( array( 'marker' => 'Stable' ), $this->query( $this->consumer() ) ) );
		$instance = new WP_Block( $parsed[0] );
		$template = $instance->inner_blocks[0]->inner_blocks[0];
		$children = $template->inner_blocks;
		$context  = $template->context;
		$original = $template->parsed_block;
		$first    = $instance->render();
		$second   = $instance->render();
		$this->assertSame( $first, $second );
		$this->assertSame( $original, $template->parsed_block );
		$this->assertSame( $context, $template->context );
		$this->assertSame( $children, $template->inner_blocks );
		$this->assertSame( 'core/post-template', $template->name );
		$this->assertSame( $this->template_type, $template->block_type );
		$this->assertCount( 4, $this->observations );
		foreach ( $this->observations as $index => $observation ) {
			$this->assertArrayHasKey( 'test/marker', $observation['context'] );
			$this->assertSame( 'Stable', $observation['context']['test/marker'] );
			$this->assertSame( $this->post_ids[ $index % 2 ], $observation['context']['postId'] );
		}
	}

	public function test_inner_placeholder_retains_unregistered_identity_and_raw_attributes() {
		$parents                                        = array();
		$this->template_type->attributes['testDefault'] = array(
			'type'    => 'string',
			'default' => 'Not a placeholder default',
		);
		$this->add_test_filter(
			'render_block_context',
			static function ( $context, $parsed_block, $parent_block ) use ( &$parents ) {
				if ( 'test/template-context-consumer' === $parsed_block['blockName'] ) {
					$parents[] = array( $parent_block->name, $parent_block->parsed_block['blockName'], $parent_block->block_type, $parent_block->context, $parent_block->attributes );
				}
				return $context;
			}
		);
		do_blocks( $this->query( $this->consumer(), array( 'className' => 'explicit' ) ) );
		$this->assertSame(
			array_fill( 0, 2, array( 'core/null', 'core/null', null, array(), array( 'className' => 'explicit' ) ) ),
			$parents
		);
	}

	public function test_inner_rows_use_placeholder_filters_without_repeating_callback_or_wrapper() {
		$callback                             = $this->template_type->render_callback;
		$calls                                = array(
			'callback'    => 0,
			'template'    => 0,
			'placeholder' => 0,
		);
		$this->template_type->render_callback = static function ( $attributes, $content, $block ) use ( $callback, &$calls ) {
			++$calls['callback'];
			return call_user_func( $callback, $attributes, $content, $block );
		};
		foreach ( array(
			'core/post-template' => 'template',
			'core/null'          => 'placeholder',
		) as $name => $key ) {
			$this->add_test_filter(
				'render_block_' . $name,
				static function ( $content ) use ( $key, &$calls ) {
					++$calls[ $key ];
					return $content;
				}
			);
		}
		$output = do_blocks( $this->query( $this->consumer() ) );
		$this->assertSame(
			array(
				'callback'    => 1,
				'template'    => 1,
				'placeholder' => 2,
			),
			$calls
		);
		$this->assertSame( 1, substr_count( $output, '<ul ' ) );
		$this->assertSame( 2, substr_count( $output, '<li ' ) );
		$this->assertCount( 2, $this->observations );
	}

	public function test_inner_placeholder_does_not_enqueue_post_template_assets() {
		$handle = 'test-post-template-context';
		wp_register_style( $handle, '/test-context.css', array(), null );
		wp_register_script( $handle, '/test-context.js', array(), null, true );
		$this->template_type->view_style_handles  = array( $handle );
		$this->template_type->view_script_handles = array( $handle );
		$queued                                   = array();
		$this->add_test_filter(
			'render_block_core/null',
			static function ( $content ) use ( $handle, &$queued ) {
				$queued[] = array( wp_style_is( $handle, 'enqueued' ), wp_script_is( $handle, 'enqueued' ) );
				return $content;
			}
		);
		try {
			do_blocks( $this->query( $this->consumer() ) );
			$this->assertSame( array( array( false, false ), array( false, false ) ), $queued );
			$this->assertTrue( wp_style_is( $handle, 'enqueued' ) );
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ) );
		} finally {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}

	public function test_enhanced_pagination_retains_row_identity_and_ancestor_context() {
		$output = do_blocks( $this->provider( array( 'marker' => 'Enhanced' ), $this->query( $this->consumer(), array(), true ) ) );
		$this->assert_row_context( 'test/marker', 'Enhanced' );
		foreach ( $this->post_ids as $post_id ) {
			$this->assertStringContainsString( 'data-wp-key="post-template-item-' . $post_id . '"', $output );
		}
	}
}
