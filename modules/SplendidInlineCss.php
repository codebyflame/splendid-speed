<?php

/**
 * Inlines and minifies all CSS used on the site,
 * thus saving the browser from having to do trips
 * to individual stylesheet files.
 */
use MatthiasMullie\Minify;

class SplendidInlineCss extends SplendidSpeed 
{
	/**
	 * A unique key used to store the setting in database.
	 */
	public $key = 'inline_css';

	/**
	 * Title of the module.
	 */
	public $title = 'Inline CSS';

	/**
	 * Label of the module.
	 */
	public $label = 'Inline all CSS stylesheets.';

	/**
	 * Description of the module.
	 */
	public $description = 'This will save the browser from making trips to each of the individual stylesheet files, thus making the page load faster.<div class="sp-option-heading-description-warning"><strong>Warning:</strong> may not work on every site due to a combination of some plugins.</div>';

	/**
	 * Cache directory.
	 */
	private $cache_dir = '';

	/**
	 * Upon initiation, set some things.
	 */
	function __construct() {
		if($this->setting($this->key)) {
			$this->cache_dir = wp_upload_dir()['basedir'] . '/' . 'splendid_speed';
		}
	}

	/**
	 * Activates any module related things.
	 * 
	 * @since 1.2
	 */
	public function activate() {
		$settings = $this->settings();
		$settings[$this->key] = true;
		update_option('splendid_speed_settings', $settings);
	}

	/**
	 * Disables any module related things.
	 * 
	 * @since 1.2
	 */
	public function disable() {
		$settings = $this->settings();
		unset($settings[$this->key]);

	 	// If cache file exists, delete it.	
		if(file_exists($this->cache_dir . '/css.cache')) {
			unlink($this->cache_dir . '/css.cache');
		}

		update_option('splendid_speed_settings', $settings);
	}

	/**
	 * Registers any module related things on page load.
	 * 
	 * @since 1.2
	 */
	public function register() {
		if($this->setting($this->key) && !is_admin()) {
			add_action('wp_print_styles', function() {
				global $wp_styles;

				// If cache dir does not exist, create it.
				if(!file_exists($this->cache_dir)) {
					wp_mkdir_p($this->cache_dir);
				}

				// Get cache file.
				$cache = @file_get_contents($this->cache_dir . '/css.cache');

				// If cache file does not exist, get data and 
				// create a cache file with the data.
				if(!$cache) {
					foreach($wp_styles->registered as $wp_style) {
						if(in_array($wp_style->handle, $wp_styles->queue)) {
							$deps = $wp_style->deps;
							$src = $wp_style->handle;

							// Get each dependency.
							foreach($deps as $dep) {
								// Do not get new dependency if we already have it.
								if(!preg_match('/splendid-speed:' . $dep .'/', $cache)) {
									$css = $this->fetch($dep);

									if(!empty($css)) {
										$cache = $cache . "\n" . '/* splendid-speed:' . $dep . ' */' . "\n" . $css;
									}
								}
							}

							$css = $this->fetch($src);

							if(!empty($css)) {
								$cache = $cache . "\n" . '/* splendid-speed:' . $src .' */' . "\n" . $css;
							}
						}
					}

					// Create cache.
					file_put_contents($this->cache_dir . '/css.cache', $cache);
				}

				// Add inline CSS to head.
				if($cache) {
					foreach($wp_styles->queue as $style) {
						// check if we have this in cache, if yes, dequeue.
						// If we don't, we want it still queued because
						// some themes load CSS only on certain pages conditionally
						// and otherwise we would break that.
						if(preg_match('/splendid-speed:' . $style .'/', $cache)) {
							$wp_styles->dequeue($style);
						}
					}

					add_action('wp_head', function() use($cache) {
						echo '<style id="splendid-speed-inline-css">' . $cache . '</style>';
					});
				}
			}, 99999);
		}
	}

	/**
	 * Fetches the CSS for a given reigstered style.
	 *
	 * @param $name
	 * @return string|bool
	 * 
	 * @since 1.2
	 */
	public function fetch($name) {
		global $wp_styles;

		$src = $wp_styles->registered[$name]->src;

		if(!$src) return false;

		// Local URLs.
		if($src[0] === '/' && $src[1] !== '/') {
			$src = get_bloginfo('url') . $src;
		}

		// Protocol relative URLs.
		if($src[0] === '/' && $src[1] === '/') {
			$src = 'http:' . $src; // I assume http, because usually even if not, it gets redirected nicely.
		}

		try {
			if(!function_exists('curl_version')) {
				return false;
			}

		    $curl = curl_init();

			curl_setopt($curl, CURLOPT_URL, $src);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);

			$contents = curl_exec($curl);
			$content_type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

			curl_close($curl);

			// If no content, or content not CSS, return nothing.
			if(!$contents || !preg_match('/text\/css/', $content_type)) {
				return false;
			}

			// Correct URL's
			$contents = preg_replace('/url\((?!http|\/\/|\'|\")/', 'url(' . substr($src, 0, strrpos($src, '/')) . '/', $contents);
			$contents = preg_replace('/url\(\'(?!http|\/\/|data:)/', "url('" . substr($src, 0, strrpos($src, '/')) . '/', $contents);
			$contents = preg_replace('/url\(\"(?!http|\/\/|data:)/', 'url("' . substr($src, 0, strrpos($src, '/')) . '/', $contents);

			$minifier = new Minify\CSS();
			$minifier->add($contents);
			$wp_styles->dequeue($name);
			return $minifier->minify();;
		} catch(Exception $e) {
			return false;
		}
	}
}