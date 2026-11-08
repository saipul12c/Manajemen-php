<?php

namespace Sentinel\NLP;

class ExternalLLM
{
    /**
     * Kirim permintaan ke penyedia AI eksternal (Google Gemini, OpenAI, Groq)
     */
    public static function query(string $provider, string $apiKey, string $message, array $history = [], ?string $systemPrompt = null): ?array
    {
        $provider = strtolower(trim($provider));

        switch ($provider) {
            case 'gemini':
            case 'google':
                return self::queryGemini($apiKey, $message, $history, $systemPrompt);

            case 'openai':
                return self::queryOpenAI($apiKey, $message, $history, $systemPrompt);

            case 'groq':
                return self::queryGroq($apiKey, $message, $history, $systemPrompt);

            default:
                return null;
        }
    }

    /**
     * Integrasi Google Gemini API (v1beta)
     */
    protected static function queryGemini(string $apiKey, string $message, array $history, ?string $systemPrompt): ?array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . urlencode($apiKey);

        $contents = [];

        // Konversi history
        foreach ($history as $h) {
            $role = ($h['role'] ?? '') === 'assistant' ? 'model' : 'user';
            $text = $h['content'] ?? '';
            if ($text !== '') {
                $contents[] = [
                    'role'  => $role,
                    'parts' => [['text' => $text]]
                ];
            }
        }

        // Tambahkan pesan saat ini
        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $message]]
        ];

        $payload = [
            'contents' => $contents
        ];

        if (!empty($systemPrompt)) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemPrompt]]
            ];
        }

        $res = self::postJson($url, $payload);
        if (!$res) return null;

        $json = json_decode($res, true);
        $reply = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($reply) {
            return [
                'reply'      => trim($reply),
                'thought'    => "Respon dihasilkan melalui komputasi cloud Google Gemini 2.0 Flash.",
                'confidence' => 1.0,
                'provider'   => 'Google Gemini'
            ];
        }

        return null;
    }

    /**
     * Integrasi OpenAI API (ChatGPT)
     */
    protected static function queryOpenAI(string $apiKey, string $message, array $history, ?string $systemPrompt): ?array
    {
        $url = "https://api.openai.com/v1/chat/completions";

        $messages = [];
        if (!empty($systemPrompt)) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($history as $h) {
            $messages[] = [
                'role'    => ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'content' => $h['content'] ?? ''
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $payload = [
            'model'       => 'gpt-4o-mini',
            'messages'    => $messages,
            'temperature' => 0.7
        ];

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ];

        $res = self::postJson($url, $payload, $headers);
        if (!$res) return null;

        $json = json_decode($res, true);
        $reply = $json['choices'][0]['message']['content'] ?? null;

        if ($reply) {
            return [
                'reply'      => trim($reply),
                'thought'    => "Respon dihasilkan melalui OpenAI GPT-4o-mini.",
                'confidence' => 1.0,
                'provider'   => 'OpenAI'
            ];
        }

        return null;
    }

    /**
     * Integrasi Groq Cloud API
     */
    protected static function queryGroq(string $apiKey, string $message, array $history, ?string $systemPrompt): ?array
    {
        $url = "https://api.groq.com/openai/v1/chat/completions";

        $messages = [];
        if (!empty($systemPrompt)) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($history as $h) {
            $messages[] = [
                'role'    => ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'content' => $h['content'] ?? ''
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $payload = [
            'model'       => 'llama-3.3-70b-versatile',
            'messages'    => $messages,
            'temperature' => 0.7
        ];

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ];

        $res = self::postJson($url, $payload, $headers);
        if (!$res) return null;

        $json = json_decode($res, true);
        $reply = $json['choices'][0]['message']['content'] ?? null;

        if ($reply) {
            return [
                'reply'      => trim($reply),
                'thought'    => "Respon kilat dihasilkan melalui Groq Llama-3.3-70B.",
                'confidence' => 1.0,
                'provider'   => 'Groq'
            ];
        }

        return null;
    }

    /**
     * Eksekutor cURL POST JSON dengan timeout aman
     */
    protected static function postJson(string $url, array $data, array $headers = ['Content-Type: application/json']): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300) ? (string)$response : null;
    }
}

