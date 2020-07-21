<?php
class HTTPS_Proxy extends Plugin {

	/* @var PluginHost $host */
	private $host;

	/* @var DiskCache $cache */
	private $cache;

	function about() {
		return array(1.0,
			"Proxies all requests via built-in secure proxy",
			"fox, timendum");
	}

	function is_public_method($method) {
		return $method === "urlproxy";
	}

	function init($host) {
		$this->host = $host;
		$this->cache = new DiskCache("urlproxy");
		$this->cache->makeDir();

		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this, 150);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this, 150);
		$host->add_hook($host::HOOK_ENCLOSURE_ENTRY, $this);

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function hook_enclosure_entry($enc) {
		if (preg_match("/image/", $enc["content_type"])) {
			$enc["content_url"] = $this->rewrite_url_if_needed($enc["content_url"]);
		}

		return $enc;
	}

	function hook_render_article($article) {
		return $this->hook_render_article_cdm($article);
	}

	public function urlproxy() {

		$url = rewrite_relative_url(get_self_url_prefix(), $_REQUEST["url"]);

		// called without user context, let's just redirect to original URL
		if (!$_SESSION["uid"]) {
			header("Location: $url");
			return;
		}

		$local_filename = sha1($url);

		if ($this->cache->exists($local_filename)) {
			header("Location: " . $this->cache->getUrl($local_filename));
			return;
			//$this->cache->send($local_filename);
		} else {
			$data = fetch_file_contents(["url" => $url, "max_size" => MAX_CACHE_FILE_SIZE]);

			if ($data) {

				$disable_cache = $this->host->get($this, "disable_cache");

				if (!$disable_cache) {
					if ($this->cache->put($local_filename, $data)) {
						header("Location: " . $this->cache->getUrl($local_filename));
						return;
					}
				}

				print $data;
			} else {
				global $fetch_last_error;
				global $fetch_last_error_code;
				global $fetch_last_error_content;

				if (function_exists("imagecreate") && !isset($_REQUEST["text"])) {
					$img = imagecreate(450, 75);

					/*$bg =*/ imagecolorallocate($img, 255, 255, 255);
					$textcolor = imagecolorallocate($img, 255, 0, 0);

					imagerectangle($img, 0, 0, 450-1, 75-1, $textcolor);

					imagestring($img, 5, 5, 5, "Proxy request failed", $textcolor);
					imagestring($img, 5, 5, 30, truncate_middle($url, 46, "..."), $textcolor);
					imagestring($img, 5, 5, 55, "HTTP Code: $fetch_last_error_code", $textcolor);

					header("Content-type: image/png");
					print imagepng($img);
					imagedestroy($img);

				} else {
					header("Content-type: text/html");

					http_response_code(400);

					print "<h1>Proxy request failed.</h1>";
					print "<p>Fetch error $fetch_last_error ($fetch_last_error_code)</p>";
					print "<p>URL: $url</p>";
					print "<textarea cols='80' rows='25'>" . htmlspecialchars($fetch_last_error_content) . "</textarea>";
				}
			}
		}
	}

	private function rewrite_url_if_needed($url) {
		/* we don't need to handle URLs where local cache already exists, tt-rss rewrites those automatically */
		if (!$this->cache->exists(sha1($url))) {

			$scheme = parse_url($url, PHP_URL_SCHEME);

			if ($all_remote) {
				$host = parse_url($url, PHP_URL_HOST);
				$self_host = parse_url(get_self_url_prefix(), PHP_URL_HOST);

				$is_remote = $host != $self_host;
			} else {
				$is_remote = false;
			}

			$whitelist = $this->host->get($this, "whitelist");
			if (strpos($url, "data:") !== 0) {
				$parts = parse_url($url);

				foreach (explode(" " , $whitelist) as $host) {
					if (substr(strtolower($parts['host']), -strlen($host)) === strtolower($host)) {
						return $url;
					}
				}

				return $this->host->get_public_method_url($this, "urlproxy", ["url" => $url]);
			}
		}

		return $url;
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function hook_render_article_cdm($article, $api_mode = false) {

		$need_saving = false;

		$doc = new DOMDocument();
		if (@$doc->loadHTML('<?xml encoding="UTF-8">' . $article["content"])) {
			$xpath = new DOMXPath($doc);
			$imgs = $xpath->query("//img[@src]");

			foreach ($imgs as $img) {
				$new_src = $this->rewrite_url_if_needed($img->getAttribute("src"));

				if ($new_src != $img->getAttribute("src")) {
					$img->setAttribute("src", $new_src);
					$img->removeAttribute("srcset");

					$need_saving = true;
				}
			}

			$vids = $xpath->query("(//video|//picture)");

			foreach ($vids as $vid) {
				if ($vid->hasAttribute("poster")) {
					$new_src = $this->rewrite_url_if_needed($vid->getAttribute("poster"));

					if ($new_src != $vid->getAttribute("poster")) {
						$vid->setAttribute("poster", $new_src);

						$need_saving = true;
					}
				}

				$vsrcs = $xpath->query("source", $vid);

				foreach ($vsrcs as $vsrc) {
					$new_src = $this->rewrite_url_if_needed($vsrc->getAttribute("src"));

					if ($new_src != $vsrc->getAttribute("src")) {
						$vid->setAttribute("src", $new_src);

						$need_saving = true;
					}
				}
			}
		}

		if ($need_saving) $article["content"] = $doc->saveHTML();

		return $article;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" 
			title=\"<i class='material-icons'>extension</i> ".__('HTTPS Proxy settings (https_proxy)')."\">";

		print "<form dojoType=\"dijit.form.Form\">";

		print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
			evt.preventDefault();
			if (this.validate()) {
				console.log(dojo.objectToQuery(this.getValues()));
				new Ajax.Request('backend.php', {
					parameters: dojo.objectToQuery(this.getValues()),
					onComplete: function(transport) {
						Notify.info(transport.responseText);
					}
				});
				//this.reset();
			}
			</script>";

		print_hidden("op", "pluginhandler");
		print_hidden("method", "save");
		print_hidden("plugin", "https_proxy");

		$disable_cache = $this->host->get($this, "disable_cache");
		print "<fieldset class=\"narrow\">";
		print_checkbox("disable_cache", $disable_cache);
		print "&nbsp;<label for=\"disable_cache\">" . __("Don't cache files locally.") . "</label>";
		print "</fieldset>";
		$whitelist = trim(strip_tags($this->host->get($this, "whitelist")));
		print "<fieldset class=\"narrow\">";
		print "<label for=\"whitelist\">" . __("Host not proxied (space separated):") . "</label>";
		print "<textarea dojoType=\"dijit.form.SimpleTextarea\" name=\"whitelist\" autocomplete=\"off\" id=\"whitelist\" value=\"" . $whitelist . "\"></textarea>";
		print "</fieldset>";

		print "<p>"; print_button("submit", __("Save"));

		print "</form>";

		print "</div>";
	}

	function save() {
		$disable_cache = checkbox_to_sql_bool($_POST["disable_cache"]);
		$whitelist = trim(strip_tags($_POST["whitelist"]));

		$this->host->set($this, "disable_cache", $disable_cache);
		$this->host->set($this, "whitelist", $whitelist);

		echo __("Configuration saved");
	}

	function api_version() {
		return 2;
	}
}
?>