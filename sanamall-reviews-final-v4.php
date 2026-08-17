<?php
/**
 * Sanamall - Digikala Style Product Reviews
 * Version: 3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/* =========================================================
 * Persian Digits
 * ========================================================= */

if ( ! function_exists( 'sanamall_fa_digits' ) ) {

	function sanamall_fa_digits( $number ) {

		return strtr(
			(string) $number,
			array(
				'0' => '۰',
				'1' => '۱',
				'2' => '۲',
				'3' => '۳',
				'4' => '۴',
				'5' => '۵',
				'6' => '۶',
				'7' => '۷',
				'8' => '۸',
				'9' => '۹',
			)
		);
	}
}


/* =========================================================
 * Current Product
 * ========================================================= */

if ( ! function_exists( 'sanamall_get_current_product' ) ) {

	function sanamall_get_current_product() {

		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		global $product;

		if ( $product instanceof WC_Product ) {
			return $product;
		}

		$product_id = get_queried_object_id();

		if ( ! $product_id ) {
			$product_id = get_the_ID();
		}

		if ( ! $product_id ) {
			return false;
		}

		return wc_get_product( $product_id );
	}
}


/* =========================================================
 * Review Data
 * ========================================================= */

if ( ! function_exists( 'sanamall_get_review_data' ) ) {

	function sanamall_get_review_data() {

		$product = sanamall_get_current_product();

		if ( ! $product ) {
			return false;
		}

		$product_id = $product->get_id();

		$reviews = get_comments(
			array(
				'post_id' => $product_id,
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => 0,
				'orderby' => 'comment_date',
				'order'   => 'DESC',
			)
		);

		$total_reviews = count( $reviews );
		$total_rating  = 0;

		foreach ( $reviews as $review ) {

			$rating = (int) get_comment_meta(
				$review->comment_ID,
				'rating',
				true
			);

			$total_rating += $rating;
		}

		$average = 0;

		if ( $total_reviews > 0 ) {

			$average = round(
				$total_rating / $total_reviews,
				1
			);
		}

		return array(
			'product'       => $product,
			'product_id'    => $product_id,
			'reviews'       => $reviews,
			'total_reviews' => $total_reviews,
			'average'       => $average,
		);
	}
}


/* =========================================================
 * Review Duplicate Protection
 * ========================================================= */

if ( ! function_exists( 'sanamall_get_guest_ip_hash' ) ) {

	function sanamall_get_guest_ip_hash() {

		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' === $ip ) {
			return '';
		}

		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}
}


if ( ! function_exists( 'sanamall_get_guest_cookie_hash' ) ) {

	function sanamall_get_guest_cookie_hash() {

		$cookie_name = 'sanamall_review_guest';

		if ( empty( $_COOKIE[ $cookie_name ] ) ) {
			return '';
		}

		$token = sanitize_text_field(
			wp_unslash( $_COOKIE[ $cookie_name ] )
		);

		if ( '' === $token ) {
			return '';
		}

		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}


if ( ! function_exists( 'sanamall_guest_has_reviewed_product' ) ) {

	function sanamall_guest_has_reviewed_product( $product_id ) {

		$ip_hash     = sanamall_get_guest_ip_hash();
		$cookie_hash = sanamall_get_guest_cookie_hash();

		if ( '' === $ip_hash && '' === $cookie_hash ) {
			return false;
		}

		$meta_query = array( 'relation' => 'OR' );

		if ( '' !== $ip_hash ) {
			$meta_query[] = array(
				'key'   => '_sanamall_guest_ip_hash',
				'value' => $ip_hash,
			);
		}

		if ( '' !== $cookie_hash ) {
			$meta_query[] = array(
				'key'   => '_sanamall_guest_cookie_hash',
				'value' => $cookie_hash,
			);
		}

		$comments = get_comments(
			array(
				'post_id'    => $product_id,
				'status'     => 'all',
				'type'       => 'review',
				'user_id'    => 0,
				'number'     => 1,
				'meta_query' => $meta_query,
			)
		);

		return ! empty( $comments );
	}
}


/* =========================================================
 * One Review Per User / Guest Per Product
 * ========================================================= */

if ( ! function_exists( 'sanamall_user_has_reviewed_product' ) ) {

	function sanamall_user_has_reviewed_product( $product_id ) {

		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		if ( is_user_logged_in() ) {

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return false;
			}

			$comments = get_comments( array(
				'post_id' => $product_id,
				'user_id' => $user_id,
				'status'  => 'all',
				'type'    => 'review',
				'number'  => 1,
			) );

			return ! empty( $comments );
		}

		return function_exists( 'sanamall_guest_has_reviewed_product' )
			? sanamall_guest_has_reviewed_product( $product_id )
			: false;
	}
}

/* =========================================================
 * Submit Review AJAX
 * ========================================================= */

if ( ! function_exists( 'sanamall_submit_review_ajax' ) ) {

	function sanamall_submit_review_ajax() {

		$nonce = isset( $_POST['nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['nonce'] ) )
			: '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'sanamall_review_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'درخواست نامعتبر است. لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.' ), 403 );
		}

		$product_id = isset( $_POST['comment_post_ID'] ) ? absint( $_POST['comment_post_ID'] ) : 0;
		if ( ! $product_id ) {
			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		}

		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			wp_send_json_error( array( 'message' => 'محصول موردنظر پیدا نشد.' ), 400 );
		}

		if ( ! comments_open( $product_id ) ) {
			wp_send_json_error( array( 'message' => 'ثبت دیدگاه برای این محصول در حال حاضر فعال نیست.' ), 403 );
		}

		if ( sanamall_user_has_reviewed_product( $product_id ) ) {
			wp_send_json_error( array( 'message' => 'شما قبلاً برای این محصول دیدگاه ثبت کرده‌اید.' ), 409 );
		}

		$author = isset( $_POST['author'] ) ? sanitize_text_field( wp_unslash( $_POST['author'] ) ) : '';
		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			if ( '' === $author ) {
				$author = $current_user->display_name;
			}
		}

		if ( '' === trim( $author ) ) {
			wp_send_json_error( array( 'message' => 'لطفاً نام خود را وارد کنید.' ), 400 );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( is_user_logged_in() && '' === $email ) {
			$current_user = wp_get_current_user();
			$email = ! empty( $current_user->user_email ) ? sanitize_email( $current_user->user_email ) : '';
		}

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'لطفاً یک ایمیل معتبر وارد کنید.' ), 400 );
		}

		$content = isset( $_POST['comment'] ) ? trim( wp_unslash( $_POST['comment'] ) ) : '';
		if ( '' === $content ) {
			wp_send_json_error( array( 'message' => 'لطفاً متن دیدگاه خود را وارد کنید.' ), 400 );
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $content ) < 3 ) {
			wp_send_json_error( array( 'message' => 'متن دیدگاه خیلی کوتاه است.' ), 400 );
		}

		$rating = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
		if ( $rating < 1 || $rating > 5 ) {
			wp_send_json_error( array( 'message' => 'لطفاً امتیاز محصول را انتخاب کنید.' ), 400 );
		}

		$comment_id = wp_new_comment( array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_content'      => wp_kses_post( $content ),
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'user_ID'              => get_current_user_id(),
		), true );

		if ( is_wp_error( $comment_id ) || ! $comment_id ) {
			wp_send_json_error( array(
				'message' => is_wp_error( $comment_id ) ? $comment_id->get_error_message() : 'ثبت دیدگاه انجام نشد. لطفاً دوباره تلاش کنید.',
			), 400 );
		}

		update_comment_meta( $comment_id, 'rating', $rating );

		if ( ! is_user_logged_in() ) {
			if ( function_exists( 'sanamall_get_guest_ip_hash' ) ) {
				$ip_hash = sanamall_get_guest_ip_hash();
				if ( '' !== $ip_hash ) {
					update_comment_meta( $comment_id, '_sanamall_guest_ip_hash', $ip_hash );
				}
			}
		}

		wp_send_json_success( array(
			'comment_id' => $comment_id,
			'name'       => $author,
			'rating'     => $rating,
			'message'    => 'دیدگاه شما با موفقیت ثبت شد.',
		) );
	}
}

add_action( 'wp_ajax_sanamall_submit_review', 'sanamall_submit_review_ajax' );
add_action( 'wp_ajax_nopriv_sanamall_submit_review', 'sanamall_submit_review_ajax' );

/* =========================================================
 * Summary Shortcode
 * ========================================================= */

function sanamall_review_summary_shortcode() {

	if (
		! function_exists( 'is_product' ) ||
		! is_product()
	) {
		return '';
	}

	$data = sanamall_get_review_data();

	if ( ! $data ) {
		return '';
	}

	$product       = $data['product'];
	$product_id    = $data['product_id'];
	$average       = $data['average'];
	$total_reviews = $data['total_reviews'];

	$product_image = $product->get_image_id()
		? wp_get_attachment_image_url(
			$product->get_image_id(),
			'thumbnail'
		)
		: wc_placeholder_img_src( 'thumbnail' );

	$product_title = $product->get_name();

	ob_start();
	?>

	<div
		class="sn-summary-box"
		dir="rtl"
	>

		<div class="sn-summary-average">

			<strong>
				<?php
				echo esc_html(
					sanamall_fa_digits(
						number_format(
							$average,
							1
						)
					)
				);
				?>
			</strong>

			<span>از ۵</span>

		</div>


		<div class="sn-summary-rating-row">

			<div class="sn-summary-stars">

				<?php for ( $i = 1; $i <= 5; $i++ ) : ?>

					<span
						class="<?php echo $i <= round( $average ) ? 'active' : ''; ?>"
					>★</span>

				<?php endfor; ?>

			</div>


			<span class="sn-summary-review-count">

				<?php
				echo esc_html(
					sanamall_fa_digits(
						$total_reviews
					)
				);
				?>

				دیدگاه

			</span>

		</div>


		<button
			type="button"
			class="sn-summary-write"
			aria-haspopup="dialog"
		>
			ثبت دیدگاه
		</button>


		<!-- =================================================
		     REVIEW MODAL
		================================================= -->

		<div
			class="sn-review-modal"
			aria-hidden="true"
		>

			<div class="sn-review-modal-backdrop"></div>


			<div
				class="sn-review-modal-dialog"
				role="dialog"
				aria-modal="true"
				aria-label="ثبت دیدگاه"
			>


				<!-- =================================================
				     MODAL HEADER
				================================================= -->

				<div class="sn-review-modal-header">

					<div class="sn-review-modal-product">

						<div class="sn-review-modal-product-image">

							<img
								src="<?php echo esc_url( $product_image ); ?>"
								alt="<?php echo esc_attr( $product_title ); ?>"
							>

						</div>


						<div class="sn-review-modal-product-info">

							<span>
								ثبت دیدگاه برای
							</span>

							<strong>
								<?php
								echo esc_html(
									$product_title
								);
								?>
							</strong>

						</div>

					</div>


					<button
						type="button"
						class="sn-review-modal-close"
						aria-label="بستن"
					>
						×
					</button>

				</div>


				<!-- =================================================
				     MODAL BODY
				================================================= -->

				<div class="sn-review-modal-body">

					<div class="sn-review-modal-heading">

						<strong>
							تجربه خود را با دیگران به اشتراک بگذارید
						</strong>

						<span>
							امتیاز و نظر شما به انتخاب بهتر سایر کاربران کمک می‌کند.
						</span>

					</div>


					<?php

					$commenter = wp_get_current_commenter();

					comment_form(
						array(

							'title_reply' => '',

							'fields' => array(

								'author' =>
									'<p class="comment-form-author">
										<label for="sn-author">نام</label>
										<input
											id="sn-author"
											name="author"
											type="text"
											value="' .
											esc_attr(
												$commenter['comment_author']
											) .
											'"
											required
										>
									</p>',

								'email' =>
									'<p class="comment-form-email">
										<label for="sn-email">ایمیل</label>
										<input
											id="sn-email"
											name="email"
											type="email"
											value="' .
											esc_attr(
												$commenter['comment_author_email']
											) .
											'"
											required
										>
									</p>',

							),

							'comment_field' =>

								'<p class="comment-form-rating">

									<div class="sn-rating-label-row">

										<label>
											امتیاز شما
										</label>

										<span
											class="sn-rating-picker"
											role="radiogroup"
											aria-label="انتخاب امتیاز"
										>

											<button type="button" data-rating="1" aria-label="۱ ستاره">★</button>
											<button type="button" data-rating="2" aria-label="۲ ستاره">★</button>
											<button type="button" data-rating="3" aria-label="۳ ستاره">★</button>
											<button type="button" data-rating="4" aria-label="۴ ستاره">★</button>
											<button type="button" data-rating="5" aria-label="۵ ستاره">★</button>

										</span>

									</div>

									<input
										type="hidden"
										name="rating"
										class="sn-rating-input"
										value=""
										required
									>

									<span class="sn-rating-hint">
										امتیاز خود را انتخاب کنید
									</span>

								</p>

								<p class="comment-form-comment">

									<label for="sn-comment">
										نظر شما
									</label>

									<textarea
										id="sn-comment"
										name="comment"
										required
										placeholder="تجربه خود از این محصول را بنویسید..."
									></textarea>

								</p>',

							'label_submit' =>
								'ثبت دیدگاه',

							'class_submit' =>
								'sn-summary-submit',

							'comment_notes_before' =>
								'',

							'comment_notes_after' =>
								'',

							'logged_in_as' =>
								'',

							'cookies' =>
								'',

						),
						$product_id
					);

					?>

					<input type="hidden" name="sn_review_nonce" value="<?php echo esc_attr( wp_create_nonce( 'sanamall_review_nonce' ) ); ?>" class="sn-review-nonce">

					<div class="sn-review-rules">

						ثبت دیدگاه به معنی موافقت با
						<a
							href="https://www.digikala.com/page/comments-rules/"
							target="_blank"
							rel="noopener noreferrer"
						>
							قوانین انتشار
						</a>
						سنا مال است.

					</div>

					<div class="sn-review-success" aria-hidden="true">
						<div class="sn-review-success-icon">✓</div>
						<strong class="sn-review-success-title"><span class="sn-review-success-name"></span> عزیز،</strong>
						<p>دیدگاه شما با موفقیت ثبت شد و پس از بررسی نمایش داده می‌شود.</p>
						<button type="button" class="sn-review-success-back">بازگشت</button>
					</div>

				</div>

			</div>

		</div>

	</div>

	<?php

	return ob_get_clean();
}

add_shortcode(
	'sanamall_review_summary',
	'sanamall_review_summary_shortcode'
);


/* =========================================================
 * Content Shortcode
 * ========================================================= */

function sanamall_review_content_shortcode() {

	if (
		! function_exists( 'is_product' ) ||
		! is_product()
	) {
		return '';
	}

	$data = sanamall_get_review_data();

	if ( ! $data ) {
		return '';
	}

	$reviews = $data['reviews'];

	ob_start();
	?>

	<div
		class="sn-content-box"
		dir="rtl"
	>

		<div class="sn-review-tabs">

			<span class="sn-tabs-title">
				مرتب‌سازی:
			</span>


			<button
				type="button"
				class="sn-review-tab active"
				data-sort="newest"
			>
				جدیدترین
			</button>


			<button
				type="button"
				class="sn-review-tab"
				data-sort="verified"
			>
				دیدگاه خریداران
			</button>


			<button
				type="button"
				class="sn-review-tab"
				data-sort="helpful"
			>
				مفیدترین
			</button>

		</div>


		<div class="sn-review-list">

			<?php if ( ! empty( $reviews ) ) : ?>

				<?php foreach ( $reviews as $review ) : ?>

					<?php

					$rating = (int) get_comment_meta(
						$review->comment_ID,
						'rating',
						true
					);

					$verified = false;

					if (
						function_exists(
							'wc_review_is_from_verified_owner'
						)
					) {

						$verified =
							wc_review_is_from_verified_owner(
								$review->comment_ID
							);
					}

					$date = strtotime(
						$review->comment_date
					);

					$author_name =
						$review->comment_author
							? $review->comment_author
							: 'کاربر';

					$avatar_letter =
						function_exists( 'mb_substr' )
							? mb_substr(
								$author_name,
								0,
								1
							)
							: substr(
								$author_name,
								0,
								1
							);

					?>

					<article
						class="sn-review-item"
						data-date="<?php echo esc_attr( $date ); ?>"
						data-rating="<?php echo esc_attr( $rating ); ?>"
						data-verified="<?php echo $verified ? '1' : '0'; ?>"
					>

						<div class="sn-review-user">

							<div class="sn-review-avatar">

								<?php
								echo esc_html(
									$avatar_letter
								);
								?>

							</div>


							<div class="sn-review-user-info">

								<div class="sn-review-name">

									<?php
									echo esc_html(
										$author_name
									);
									?>

								</div>


								<?php if ( $verified ) : ?>

									<span class="sn-review-buyer">
										✓ خریدار این محصول
									</span>

								<?php endif; ?>

							</div>

						</div>


						<div class="sn-review-date">

							<?php
							echo esc_html(
								wp_date(
									'j F Y',
									$date
								)
							);
							?>

						</div>


						<div class="sn-review-stars">

							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>

								<span
									class="<?php echo $i <= $rating ? 'active' : ''; ?>"
								>★</span>

							<?php endfor; ?>

						</div>


						<div class="sn-review-text">

							<?php
							echo wpautop(
								wp_kses_post(
									$review->comment_content
								)
							);
							?>

						</div>


						<div class="sn-review-help">

							<span>
								آیا این دیدگاه برایتان مفید بود؟
							</span>


							<button
								type="button"
								class="sn-help-btn"
							>
								بله
							</button>


							<button
								type="button"
								class="sn-help-btn"
							>
								خیر
							</button>

						</div>

					</article>

				<?php endforeach; ?>

			<?php else : ?>

				<div class="sn-no-reviews">

					<div class="sn-no-reviews-icon">
						☆
					</div>

					<strong>
						هنوز دیدگاهی ثبت نشده است
					</strong>

					<span>
						اولین نفری باشید که تجربه خود را ثبت می‌کند.
					</span>

				</div>

			<?php endif; ?>

		</div>

	</div>

	<?php

	return ob_get_clean();
}

add_shortcode(
	'sanamall_review_content',
	'sanamall_review_content_shortcode'
);


/* =========================================================
 * CSS
 * ========================================================= */

function sanamall_reviews_styles() {

	if (
		! function_exists( 'is_product' ) ||
		! is_product()
	) {
		return;
	}

	?>

	<style>

	/* =====================================================
	   VARIABLES
	===================================================== */

	:root {
		--sn-sticky-header-offset: 90px;
		--sn-red: #ef394e;
		--sn-text: #242424;
		--sn-muted: #818181;
		--sn-border: #eeeeef;
	}


	/* =====================================================
	   GLOBAL FONT
	===================================================== */

	.sn-summary-box,
	.sn-content-box,
	.sn-review-modal,
	.sn-review-modal * {
		font-family: IRANYekan, sans-serif !important;
	}


	/* =====================================================
	   SUMMARY
	===================================================== */

	.sn-summary-box {
		width: 100%;
		box-sizing: border-box;
		padding: 24px;
		border: 1px solid #f0f0f1;
		border-radius: 12px;
		background: #fff;
	}

	.sn-summary-average {
		display: flex;
		align-items: baseline;
		gap: 5px;
		margin-bottom: 12px;
	}

	.sn-summary-average strong {
		color: var(--sn-text);
		font-size: 32px;
		line-height: 1;
		font-weight: 700;
	}

	.sn-summary-average span {
		color: #a1a1a1;
		font-size: 12px;
	}

	.sn-summary-rating-row {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
		margin-bottom: 22px;
	}

	.sn-summary-stars,
	.sn-review-stars {
		display: flex;
		direction: ltr;
		gap: 2px;
	}

	.sn-summary-stars span,
	.sn-review-stars span {
		font-family: Arial, sans-serif !important;
		font-size: 16px;
		color: #ddd;
		line-height: 1;
	}

	.sn-summary-stars span.active,
	.sn-review-stars span.active {
		color: #f9a825;
	}

	.sn-summary-review-count {
		color: #818181;
		font-size: 11px;
	}

	.sn-summary-write {
		width: 100%;
		height: 44px;
		border: 1px solid var(--sn-red);
		border-radius: 8px;
		background: #fff;
		color: var(--sn-red);
		font-family: IRANYekan, sans-serif !important;
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
		transition:
			background .2s ease,
			color .2s ease,
			box-shadow .2s ease;
	}

	.sn-summary-write:hover,
	.sn-summary-write:focus,
	.sn-summary-write:active {
		background: #fff !important;
		color: var(--sn-red) !important;
		border-color: var(--sn-red) !important;
		box-shadow: none !important;
	}


	/* =====================================================
	   MODAL
	===================================================== */

	.sn-review-modal {
		position: fixed;
		z-index: 999999;
		top: 0;
		right: 0;
		bottom: 0;
		left: 0;
		display: none;
		align-items: flex-start;
		justify-content: center;
		padding:
			calc(
				var(--sn-sticky-header-offset) + 16px
			)
			20px
			20px;
		box-sizing: border-box;
	}

	.sn-review-modal.open {
		display: flex;
	}

	.sn-review-modal-backdrop {
		position: absolute;
		inset: 0;
		background: rgba(0, 0, 0, .48);
		backdrop-filter: blur(2px);
		-webkit-backdrop-filter: blur(2px);
		animation: snModalFade .2s ease;
	}

	.sn-review-modal-dialog {
		position: relative;
		z-index: 2;

		/* عرض جمع‌وجورتر */
		width: min(540px, 100%);

		max-height: calc(
			100vh -
			var(--sn-sticky-header-offset) -
			36px
		);

		overflow: hidden;
		border-radius: 16px;
		background: #fff;

		box-shadow:
			0 20px 60px rgba(0, 0, 0, .18);

		animation: snModalIn .25s ease;
	}

	@keyframes snModalFade {

		from {
			opacity: 0;
		}

		to {
			opacity: 1;
		}

	}

	@keyframes snModalIn {

		from {
			opacity: 0;
			transform: translateY(-15px) scale(.98);
		}

		to {
			opacity: 1;
			transform: translateY(0) scale(1);
		}

	}


	/* =====================================================
	   MODAL HEADER
	===================================================== */

	.sn-review-modal-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 15px;
		min-height: 76px;
		padding: 12px 18px;
		border-bottom: 1px solid #f1f1f1;
		box-sizing: border-box;
		background: #fff;
	}

	.sn-review-modal-product {
		display: flex;
		align-items: center;
		min-width: 0;
		gap: 12px;
		flex: 1 1 auto;
	}

	.sn-review-modal-product-image {
		flex: 0 0 52px;
		width: 52px;
		height: 52px;
		overflow: hidden;
		border: 1px solid #f0f0f0;
		border-radius: 9px;
		background: #fff;
	}

	.sn-review-modal-product-image img {
		display: block;
		width: 100%;
		height: 100%;
		object-fit: contain;
	}


	/* =====================================================
	   PRODUCT TITLE
	===================================================== */

	.sn-review-modal-product-info {
		display: flex;
		flex-direction: column;
		min-width: 0;
		flex: 1 1 auto;
		gap: 5px;
		overflow: hidden;
	}

	.sn-review-modal-product-info span {
		color: #999;
		font-size: 10px;
	}

	.sn-review-modal-product-info strong {
		display: block;

		color: rgb(35, 37, 78);
		direction: rtl;
		flex-grow: 0;
		font-family: IRANYekan, sans-serif !important;
		font-size: 14px;
		font-weight: 600;

		/* همیشه یک خط */
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;

		line-height: 1.8;
	}

	.sn-review-modal-close {
		flex: 0 0 34px;
		width: 34px;
		height: 34px;
		padding: 0;
		border: 0;
		border-radius: 50%;
		background: #f7f7f7;
		color: #555;
		font-family: Arial, sans-serif !important;
		font-size: 24px;
		line-height: 32px;
		cursor: pointer;
		transition: .2s ease;
	}

	.sn-review-modal-close:hover {
		background: #f1f1f1;
		color: #222;
	}


	/* =====================================================
	   MODAL BODY
	===================================================== */

	.sn-review-modal-body {
		max-height: calc(
			100vh -
			var(--sn-sticky-header-offset) -
			112px
		);
		overflow-y: auto;
		padding: 22px;
		box-sizing: border-box;
		scrollbar-width: thin;
	}

	.sn-review-modal-heading {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin-bottom: 20px;
	}

	.sn-review-modal-heading strong {
		color: #292929;
		font-size: 14px;
		font-weight: 700;
	}

	.sn-review-modal-heading span {
		color: #999;
		font-size: 10px;
		line-height: 1.8;
	}


	/* =====================================================
	   FORM
	===================================================== */

	.sn-review-modal-body form {
		margin: 0;
	}

	/*
	 * نام و ایمیل در یک ردیف
	 * ایمیل عریض‌تر از نام
	 */

	.sn-review-modal-body .comment-form-author,
	.sn-review-modal-body .comment-form-email {
		display: inline-block;
		vertical-align: top;
		box-sizing: border-box;
		margin: 0;
	}

	.sn-review-modal-body .comment-form-author {
		width: calc(40% - 7px);
		margin-left: 10px;
	}

	.sn-review-modal-body .comment-form-email {
		width: calc(60% - 7px);
	}

	.sn-review-modal-body label {
		display: block;
		margin-bottom: 7px;
		color: #444;
		font-size: 11px;
		font-weight: 600;
	}

	.sn-review-modal-body input[type="text"],
	.sn-review-modal-body input[type="email"],
	.sn-review-modal-body textarea {
		width: 100%;
		box-sizing: border-box;
		border: 1px solid #dedee2;
		border-radius: 8px;
		background: #fff;
		color: #333;
		font-family: IRANYekan, sans-serif !important;
		font-size: 12px;
		outline: none;
		transition:
			border-color .2s ease,
			box-shadow .2s ease;
	}

	.sn-review-modal-body input[type="text"],
	.sn-review-modal-body input[type="email"] {
		height: 43px;
		padding: 0 12px;
	}

	.sn-review-modal-body textarea {
		min-height: 125px;
		padding: 11px 12px;
		resize: vertical;
		line-height: 2;
	}

	.sn-review-modal-body input:focus,
	.sn-review-modal-body textarea:focus {
		border-color: #c9c9c9;
		box-shadow: 0 0 0 3px rgba(0, 0, 0, .025);
	}


	/* =====================================================
	   RATING
	===================================================== */

	.sn-review-modal-body .comment-form-rating {
		margin: 18px 0 17px;
	}

	.sn-rating-label-row {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.sn-rating-label-row label {
		flex: 0 0 auto;
		margin: 0;
		line-height: 34px;
	}

	/*
	 * ستاره‌ها نزدیک به هم و هم‌ردیف امتیاز شما
	 */

	.sn-rating-picker {
		display: flex;
		align-items: center;
		direction: ltr;
		gap: 0;
		height: 34px;
	}

	.sn-rating-picker button {
		width: 27px;
		height: 34px;
		margin: 0;
		padding: 0;
		border: 0;
		background: transparent;
		color: #d9d9dc;
		font-family: Arial, sans-serif !important;
		font-size: 25px;
		line-height: 34px;
		cursor: pointer;
		transition:
			color .15s ease,
			transform .15s ease;
	}

	.sn-rating-picker button:hover {
		transform: scale(1.06);
	}

	.sn-rating-picker button.active {
		color: #f9a825;
	}

	.sn-rating-hint {
		display: block;
		margin-top: 4px;
		color: #aaa;
		font-size: 9px;
	}


	/* =====================================================
	   SUBMIT
	===================================================== */

	.sn-summary-submit {
		width: 100%;
		height: 45px;
		margin-top: 16px;
		border: 0;
		border-radius: 8px;
		background: var(--sn-red);
		color: #fff;
		font-family: IRANYekan, sans-serif !important;
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
		transition:
			background .2s ease,
			box-shadow .2s ease,
			opacity .2s ease;
	}

	/* دکمه تا قبل از نوشتن نظر غیرفعال */

	.sn-summary-submit:disabled,
	.sn-summary-submit.sn-submit-disabled {
		background: #d8d8d8 !important;
		border-color: #d8d8d8 !important;
		color: #999 !important;
		cursor: not-allowed !important;
		box-shadow: none !important;
		opacity: 1;
	}

	.sn-summary-submit:not(:disabled):hover,
	.sn-summary-submit:not(:disabled):focus,
	.sn-summary-submit:not(:disabled):active {
		background: var(--sn-red) !important;
		color: #fff !important;
		border-color: transparent !important;
		box-shadow: none !important;
	}

	.sn-review-modal-body .comment-notes,
	.sn-review-modal-body .comment-form-cookies-consent,
	.sn-review-modal-body .logged-in-as {
		display: none !important;
	}


	/* =====================================================
	   REVIEW RULES
	===================================================== */

	.sn-review-rules {
		margin-top: 9px;
		padding: 0 8px;

		text-align: center;

		color: #999;
		font-family: IRANYekan, sans-serif !important;
		font-size: 9px;
		font-weight: 400;
		line-height: 1.9;
	}

	.sn-review-rules a {
		color: #555;
		font-family: IRANYekan, sans-serif !important;
		text-decoration: none;
		font-weight: 500;
	}

	.sn-review-rules a:hover {
		color: var(--sn-red);
		text-decoration: underline;
	}


	/* =====================================================
	   SUCCESS POPUP
	===================================================== */

	.sn-review-success {
		display: none;
		min-height: 330px;
		padding: 42px 28px 30px;
		box-sizing: border-box;
		align-items: center;
		justify-content: center;
		flex-direction: column;
		text-align: center;
		direction: rtl;
	}

	.sn-review-modal.success-state .sn-review-modal-header {
		display: none;
	}

	.sn-review-modal.success-state .sn-review-modal-body {
		display: block;
		padding: 0;
		max-height: none;
		overflow: visible;
	}

	.sn-review-modal.success-state .sn-review-modal-body > :not(.sn-review-success) {
		display: none !important;
	}

	.sn-review-modal.success-state .sn-review-success {
		display: flex;
	}

	.sn-review-success-icon {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 58px;
		height: 58px;
		margin-bottom: 18px;
		border-radius: 50%;
		background: #eaf8f0;
		color: #159957;
		font-size: 27px;
		font-weight: 700;
	}

	.sn-review-success-title {
		color: #24254e;
		font-size: 18px;
		font-weight: 700;
		line-height: 1.9;
	}

	.sn-review-success p {
		max-width: 360px;
		margin: 8px auto 24px;
		color: #888;
		font-size: 11px;
		line-height: 2;
	}

	.sn-review-success-back {
		width: 100%;
		max-width: 240px;
		height: 44px;
		border: 0;
		border-radius: 8px;
		background: var(--sn-red);
		color: #fff;
		font-family: IRANYekan, sans-serif !important;
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
		transition: .2s ease;
	}

	.sn-review-success-back:hover {
		background: var(--sn-red);
		color: #fff;
		box-shadow: none;
	}


	/* =====================================================
	   CONTENT
	===================================================== */

	.sn-content-box {
		width: 100%;
		box-sizing: border-box;
	}

	.sn-review-tabs {
		display: flex;
		align-items: center;
		gap: 26px;
		height: 58px;
		border-bottom: 1px solid var(--sn-border);
		overflow-x: auto;
		scrollbar-width: none;
	}

	.sn-review-tabs::-webkit-scrollbar {
		display: none;
	}

	.sn-tabs-title {
		flex-shrink: 0;
		color: #333;
		font-size: 12px;
		font-weight: 600;
	}

	.sn-review-tab {
		position: relative;
		flex-shrink: 0;
		height: 58px;
		padding: 0;
		border: 0;
		background: transparent;
		color: #777;
		font-family: IRANYekan, sans-serif !important;
		font-size: 12px;
		cursor: pointer;
	}

	.sn-review-tab.active {
		color: var(--sn-red);
		font-weight: 600;
	}

	.sn-review-tab.active:after {
		content: "";
		position: absolute;
		right: 0;
		left: 0;
		bottom: -1px;
		height: 2px;
		border-radius: 3px 3px 0 0;
		background: var(--sn-red);
	}


	/* =====================================================
	   REVIEW ITEM
	===================================================== */

	.sn-review-item {
		position: relative;
		padding: 24px 0 27px;
		border-bottom: 1px solid var(--sn-border);
	}

	.sn-review-user {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-bottom: 10px;
	}

	.sn-review-avatar {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 36px;
		height: 36px;
		border-radius: 50%;
		background: #f3f3f3;
		color: #777;
		font-size: 13px;
		font-weight: 600;
	}

	.sn-review-user-info {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.sn-review-name {
		color: #333;
		font-size: 12px;
		font-weight: 600;
	}

	.sn-review-buyer {
		padding: 4px 7px;
		border-radius: 5px;
		background: #f0faf4;
		color: #159957;
		font-size: 9px;
	}

	.sn-review-date {
		position: absolute;
		top: 27px;
		left: 0;
		color: #aaa;
		font-size: 10px;
	}

	.sn-review-stars {
		margin-bottom: 10px;
	}

	.sn-review-text {
		max-width: 850px;
		color: #525252;
		font-size: 12px;
		line-height: 2.2;
	}

	.sn-review-text p {
		margin: 0 0 6px;
	}

	.sn-review-help {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-top: 16px;
		color: #999;
		font-size: 10px;
	}

	.sn-help-btn {
		padding: 5px 11px;
		border: 1px solid #e5e5e5;
		border-radius: 6px;
		background: #fff;
		color: #777;
		font-family: IRANYekan, sans-serif !important;
		font-size: 10px;
		cursor: pointer;
		transition: .2s ease;
	}

	.sn-help-btn:hover {
		border-color: #ccc;
		background: #fafafa;
	}


	/* =====================================================
	   EMPTY
	===================================================== */

	.sn-no-reviews {
		padding: 60px 20px;
		text-align: center;
	}

	.sn-no-reviews-icon {
		margin-bottom: 8px;
		color: #ddd;
		font-size: 42px;
	}

	.sn-no-reviews strong {
		display: block;
		color: #555;
		font-size: 13px;
	}

	.sn-no-reviews span {
		display: block;
		margin-top: 6px;
		color: #999;
		font-size: 10px;
	}


	/* =====================================================
	   MOBILE
	===================================================== */

	@media (max-width: 767px) {

		:root {
			--sn-sticky-header-offset: 70px;
		}

		.sn-summary-box {
			padding: 18px;
		}

		.sn-summary-average strong {
			font-size: 28px;
		}

		.sn-review-tabs {
			gap: 18px;
		}

		.sn-review-item {
			padding: 20px 0 23px;
		}

		.sn-review-date {
			position: static;
			margin-top: 4px;
			margin-bottom: 9px;
		}

		.sn-review-user-info {
			flex-wrap: wrap;
		}

		.sn-review-text {
			font-size: 11px;
		}


		/* =================================================
		   MOBILE MODAL
		================================================= */

		.sn-review-modal {
			align-items: flex-start;
			padding:
				calc(
					var(--sn-sticky-header-offset) + 8px
				)
				10px
				10px;
		}

		.sn-review-modal-dialog {
			width: 100%;
			max-width: 540px;
			max-height: calc(
				100vh -
				var(--sn-sticky-header-offset) -
				18px
			);
			border-radius: 14px;
		}

		.sn-review-modal-header {
			min-height: 68px;
			padding: 9px 12px;
		}

		.sn-review-modal-product-image {
			flex-basis: 44px;
			width: 44px;
			height: 44px;
		}

		.sn-review-modal-product-info strong {
			font-size: 12px;
		}

		.sn-review-modal-body {
			max-height: calc(
				100vh -
				var(--sn-sticky-header-offset) -
				100px
			);
			padding: 18px;
		}

		.sn-review-modal-heading strong {
			font-size: 13px;
		}


		/*
		 * در موبایل هم نام و ایمیل کنار هم می‌مانند
		 * ولی عرض‌ها کمی متعادل‌تر می‌شوند.
		 */

		.sn-review-modal-body .comment-form-author {
			width: calc(42% - 6px);
			margin-left: 8px;
		}

		.sn-review-modal-body .comment-form-email {
			width: calc(58% - 6px);
		}

		.sn-rating-label-row {
			gap: 6px;
		}

		.sn-rating-picker button {
			width: 25px;
			height: 32px;
			font-size: 23px;
		}

		.sn-review-rules {
			font-size: 8.5px;
			padding: 0 4px;
		}

		.sn-review-success {
			min-height: 300px;
			padding: 35px 20px 25px;
		}

		.sn-review-success-title {
			font-size: 16px;
		}

		.sn-review-success p {
			font-size: 10px;
		}

	}


	/* =====================================================
	   VERY SMALL MOBILE
	===================================================== */

	@media (max-width: 380px) {

		.sn-review-modal-body {
			padding: 15px;
		}

		.sn-review-modal-body .comment-form-author {
			width: calc(40% - 5px);
			margin-left: 7px;
		}

		.sn-review-modal-body .comment-form-email {
			width: calc(60% - 5px);
		}

		.sn-rating-label-row {
			gap: 4px;
		}

		.sn-rating-picker button {
			width: 23px;
			font-size: 22px;
		}

		.sn-review-modal-product-info strong {
			font-size: 11px;
		}

	}

	</style>

	<?php
}

add_action(
	'wp_head',
	'sanamall_reviews_styles',
	100
);


/* =========================================================
 * JavaScript
 * ========================================================= */

function sanamall_reviews_scripts() {

	if (
		! function_exists( 'is_product' ) ||
		! is_product()
	) {
		return;
	}

	?>

	<script>

	document.addEventListener(
		'DOMContentLoaded',
		function() {


			/* =================================================
			   MODAL
			================================================= */

			document.querySelectorAll(
				'.sn-summary-box'
			).forEach(
				function(box) {

					const openButton =
						box.querySelector(
							'.sn-summary-write'
						);

					const modal =
						box.querySelector(
							'.sn-review-modal'
						);

					if (
						! openButton ||
						! modal
					) {
						return;
					}


					const closeButton =
						modal.querySelector(
							'.sn-review-modal-close'
						);

					const backdrop =
						modal.querySelector(
							'.sn-review-modal-backdrop'
						);


					function openModal() {

						modal.classList.add(
							'open'
						);

						modal.setAttribute(
							'aria-hidden',
							'false'
						);

						document.body.classList.add(
							'sn-review-modal-open'
						);

						document.body.style.overflow =
							'hidden';

					}


					function closeModal() {

						modal.classList.remove(
							'open'
						);

						modal.setAttribute(
							'aria-hidden',
							'true'
						);

						document.body.classList.remove(
							'sn-review-modal-open'
						);

						document.body.style.overflow =
							'';

					}


					openButton.addEventListener(
						'click',
						openModal
					);


					if ( closeButton ) {

						closeButton.addEventListener(
							'click',
							closeModal
						);

					}


					if ( backdrop ) {

						backdrop.addEventListener(
							'click',
							closeModal
						);

					}


					document.addEventListener(
						'keydown',
						function(event) {

							if (
								event.key === 'Escape' &&
								modal.classList.contains(
									'open'
								)
							) {

								closeModal();

							}

						}
					);

				}
			);


			/* =================================================
			   STAR PICKER
			================================================= */

			document.querySelectorAll(
				'.sn-rating-picker'
			).forEach(
				function(picker) {

					const stars =
						picker.querySelectorAll(
							'button'
						);

					const input =
						picker.parentElement.parentElement.querySelector(
							'.sn-rating-input'
						);

					const hint =
						picker.parentElement.parentElement.querySelector(
							'.sn-rating-hint'
						);


					stars.forEach(
						function(star) {

							star.addEventListener(
								'mouseenter',
								function() {

									const value =
										Number(
											star.dataset.rating
										);

									stars.forEach(
										function(item) {

											item.classList.toggle(
												'active',
												Number(
													item.dataset.rating
												) <= value
											);

										}
									);

								}
							);


							star.addEventListener(
								'click',
								function() {

									const value =
										Number(
											star.dataset.rating
										);

									if ( input ) {

										input.value =
											value;

									}

									stars.forEach(
										function(item) {

											item.classList.toggle(
												'active',
												Number(
													item.dataset.rating
												) <= value
											);

										}
									);


									if ( hint ) {

										hint.textContent =
											'امتیاز انتخاب‌شده: ' +
											toPersian(
												value
											) +
											' از ۵';

										hint.style.color =
											'#159957';

									}

								}
							);

						}
					);


					picker.addEventListener(
						'mouseleave',
						function() {

							const current =
								input
									? Number(
										input.value
									)
									: 0;

							stars.forEach(
								function(star) {

									star.classList.toggle(
										'active',
										Number(
											star.dataset.rating
										) <= current
									);

								}
							);

						}
					);

				}
			);


			/* =================================================
			   COMMENT TEXT -> SUBMIT BUTTON
			================================================= */

			document.querySelectorAll(
				'.sn-review-modal'
			).forEach(
				function(modal) {

					const form =
						modal.querySelector(
							'form.comment-form'
						);

					if ( ! form ) {
						return;
					}


					const textarea =
						form.querySelector(
							'textarea[name="comment"]'
						);

					const submitButton =
						form.querySelector(
							'.sn-summary-submit'
						);


					if (
						! textarea ||
						! submitButton
					) {
						return;
					}


					function updateSubmitButton() {

						const hasText =
							textarea.value.trim().length > 0;

						submitButton.disabled =
							! hasText;

						submitButton.classList.toggle(
							'sn-submit-disabled',
							! hasText
						);

					}


					/* حالت اولیه */

					updateSubmitButton();


					/* هنگام تایپ */

					textarea.addEventListener(
						'input',
						updateSubmitButton
					);

				}
			);


			/* =================================================
			   REVIEW SUBMISSION
			================================================= */

			document.querySelectorAll( '.sn-review-modal' ).forEach(
				function( modal ) {

					const form = modal.querySelector( 'form.comment-form' );
					const body = modal.querySelector( '.sn-review-modal-body' );
					const success = modal.querySelector( '.sn-review-success' );
					const successName = modal.querySelector( '.sn-review-success-name' );
					const backButton = modal.querySelector( '.sn-review-success-back' );
					const submitButton = form ? form.querySelector( '.sn-summary-submit' ) : null;
					const nonceInput = modal.querySelector( '.sn-review-nonce' );

					if ( ! form || ! body || ! success || ! backButton ) {
						return;
					}

					form.addEventListener( 'submit', function( event ) {
						event.preventDefault();

						if ( submitButton && submitButton.disabled ) {
							return;
						}

						if ( ! form.checkValidity() ) {
							form.reportValidity();
							return;
						}

						const originalText = submitButton ? submitButton.textContent : 'ثبت دیدگاه';
						if ( submitButton ) {
							submitButton.disabled = true;
							submitButton.textContent = 'در حال ارسال...';
						}

						const formData = new FormData( form );
						formData.set( 'action', 'sanamall_submit_review' );

						if ( nonceInput ) {
							formData.set( 'nonce', nonceInput.value );
						}

						fetch(
							'<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
							{
								method: 'POST',
								body: formData,
								credentials: 'same-origin',
								headers: { 'Accept': 'application/json' }
							}
						)
						.then( function( response ) {
							return response.text().then( function( text ) {
								let result;
								try {
									result = JSON.parse( text );
								} catch ( error ) {
									throw new Error( 'پاسخ سرور معتبر نیست.' );
								}
								return result;
							} );
						} )
						.then( function( result ) {
							if ( ! result || ! result.success ) {
								throw new Error( result && result.data && result.data.message ? result.data.message : 'ارسال دیدگاه انجام نشد. لطفاً دوباره تلاش کنید.' );
							}

							if ( successName ) {
								successName.textContent = result.data.name || '';
							}

							modal.classList.add( 'success-state' );
							success.setAttribute( 'aria-hidden', 'false' );
						} )
						.catch( function( error ) {
							alert( error && error.message ? error.message : 'در ارسال دیدگاه خطایی رخ داد. لطفاً دوباره تلاش کنید.' );
							if ( submitButton ) {
								submitButton.disabled = false;
								submitButton.textContent = originalText;
							}
						} );
					} );

					backButton.addEventListener( 'click', function() {
						modal.classList.remove( 'success-state' );
						success.setAttribute( 'aria-hidden', 'true' );
						modal.classList.remove( 'open' );
						modal.setAttribute( 'aria-hidden', 'true' );
						document.body.classList.remove( 'sn-review-modal-open' );
						document.body.style.overflow = '';
						body.style.display = '';
						form.reset();
						const ratingInput = form.querySelector( '.sn-rating-input' );
						if ( ratingInput ) { ratingInput.value = ''; }
						modal.querySelectorAll( '.sn-rating-picker button' ).forEach( function( star ) { star.classList.remove( 'active' ); } );
						if ( submitButton ) {
							submitButton.disabled = true;
							submitButton.classList.add( 'sn-submit-disabled' );
							submitButton.textContent = 'ثبت دیدگاه';
						}
					} );
				}
			);

			/* =================================================
			   REVIEW SORT
			================================================= */

			document.querySelectorAll(
				'.sn-review-tab'
			).forEach(
				function(button) {

					button.addEventListener(
						'click',
						function() {

							const box =
								button.closest(
									'.sn-content-box'
								);

							if ( ! box ) {
								return;
							}

							const list =
								box.querySelector(
									'.sn-review-list'
								);

							if ( ! list ) {
								return;
							}


							box.querySelectorAll(
								'.sn-review-tab'
							).forEach(
								function(item) {

									item.classList.remove(
										'active'
									);

								}
							);


							button.classList.add(
								'active'
							);


							const sort =
								button.dataset.sort;


							const reviews =
								Array.from(
									list.querySelectorAll(
										'.sn-review-item'
									)
								);


							reviews.sort(
								function(a, b) {

									const dateA =
										Number(
											a.dataset.date
										);

									const dateB =
										Number(
											b.dataset.date
										);

									const ratingA =
										Number(
											a.dataset.rating
										);

									const ratingB =
										Number(
											b.dataset.rating
										);

									const verifiedA =
										Number(
											a.dataset.verified
										);

									const verifiedB =
										Number(
											b.dataset.verified
										);


									if (
										sort ===
										'verified'
									) {

										if (
											verifiedA !==
											verifiedB
										) {

											return (
												verifiedB -
												verifiedA
											);

										}

										return (
											dateB -
											dateA
										);

									}


									if (
										sort ===
										'helpful'
									) {

										if (
											ratingA !==
											ratingB
										) {

											return (
												ratingB -
												ratingA
											);

										}

										return (
											dateB -
											dateA
										);

									}


									return (
										dateB -
										dateA
									);

								}
							);


							reviews.forEach(
								function(review) {

									list.appendChild(
										review
									);

								}
							);

						}
					);

				}
			);


			/* =================================================
			   Persian Digits
			================================================= */

			function toPersian(value) {

				return String(
					value
				).replace(
					/\d/g,
					function(digit) {

						return [
							'۰',
							'۱',
							'۲',
							'۳',
							'۴',
							'۵',
							'۶',
							'۷',
							'۸',
							'۹'
						][digit];

					}
				);

			}

		}
	);

	</script>

	<?php
}

add_action(
	'wp_footer',
	'sanamall_reviews_scripts',
	100
);