<?php
/**
 * Gemini CLI AJAX Handler
 * 
 * Root-level PHP entry point for AJAX requests from the React UI.
 * Unraid's NGINX only executes PHP files at the plugin root level,
 * NOT from subdirectories like includes/. This file simply includes
 * the backend handler which detects $_GET['action'] and responds.
 */
require_once __DIR__ . '/includes/GeminiSettings.php';
