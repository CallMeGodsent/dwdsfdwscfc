<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use NXP\MathExecutor;
use Symfony\Component\DomCrawler\Crawler;

class TelegramController extends Controller
{
    protected $telegramApiUrl;
    protected $emojis = ['😊', '😂', '👍', '🎉', '🤔', '🙌', '💡', '🚀'];
    protected $numberWords = [
        'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
    ];
    protected $rssFeeds = [
        'global' => [
            'http://rss.cnn.com/rss/money_technology.rss',
            'https://feeds.bbci.co.uk/news/rss.xml',
            'https://www.npr.org/rss/rss.php?id=1001',
        ],
        'nigeria' => [
            'https://www.legit.ng/rss/all.rss',
            'https://thenationonlineng.net/feed/',
            'https://www.premiumtimesng.com/feed',
        ],
    ];

    public function __construct()
    {
        $this->telegramApiUrl = 'https://api.telegram.org/bot' . env('TELEGRAM_BOT_TOKEN') . '/sendMessage';
    }

    public function handleWebhook(Request $request)
    {
        $message = $request->input('message.text');
        $chatId = $request->input('message.chat.id');
        $username = $request->input('message.chat.username');
        $messageId = $request->input('message.message_id'); // Get the message ID

        if (!$message || !$chatId) {
            return response()->json(['status' => 'error', 'message' => 'No message received'], 400);
        }

// Check if the message contains 'image of'
if (strpos($message, 'image of') !== false) {
    // Remove everything before and including 'image of'
    $query = trim(substr($message, strpos($message, 'image of') + strlen('image of'))); 

    // Fetch an image from Unsplash
    $imageUrl = $this->fetchImageFromUnsplash($query);

    if ($imageUrl) {
        // Send the image to the user
        $this->sendImage($chatId, $imageUrl, $messageId);
    } else {
        // If no image is found, send a fallback message
        $this->sendMessage($chatId, 'Sorry, I could not find an image for "' . $query . '".', $messageId);
    }
}
 else {


                // Check if the user is already in "airtime mode"
    $inAirtimeMode = Cache::get("airtime_mode_{$chatId}", false);

    if ($inAirtimeMode) {
        if ($message === 'cancel') {
            // Code to execute if $message is exactly 'cancel'
        } elseif ($message === 'stop') {
            Cache::forget("airtime_mode_{$chatId}");
            $this->sendMessage($chatId, 'You have exited airtime mode. 😊', $messageId);
            return;
        } elseif ($message === 'no') {
            Cache::forget("airtime_mode_{$chatId}");
            $this->sendMessage($chatId, 'You have exited airtime mode. 😊', $messageId);
            return;
        }

        // Check if expecting username
        if (!Cache::has("airtime_username_{$chatId}")) {
            if (preg_match('/^[\w\d]+$/', $message)) {
                Cache::put("airtime_username_{$chatId}", $message, now()->addMinutes(10)); // Store username for 10 minutes
                $this->sendMessage($chatId, 'Thanks! Now, tell me your MiraSub password. 😊', $messageId);
            } else {
                $this->sendMessage($chatId, 'Please provide a valid username (no special characters). 😅', $messageId);
            }
            return;
        }

        // Handle password entry and validate user
        if (!Cache::has("airtime_password_{$chatId}")) {
            $username = Cache::get("airtime_username_{$chatId}");

            if ($this->validateCredentials($username, $message)) {
                Cache::put("airtime_password_{$chatId}", $message, now()->addMinutes(10)); // Store password for 10 minutes

                // Generate dynamic passkey
                $dynamicPasskey = Str::random(16);
                Cache::put("airtime_passkey_{$chatId}", $dynamicPasskey, now()->addMinutes(10)); // Store passkey for 10 minutes

                $this->sendMessage($chatId, 'Credentials validated! Your passkey is: ' . $dynamicPasskey . '. Which country would you like to send airtime to? 🌍', $messageId);
                Cache::put("airtime_step_{$chatId}", 'country'); // Move to country step
            } else {
                $this->sendMessage($chatId, 'Incorrect password. Please try again. 🙁', $messageId);
            }
            return;
        }

        // Ask for country if in the country step
        if (Cache::get("airtime_step_{$chatId}") === 'country') {
            Cache::put("airtime_country_{$chatId}", $message, now()->addMinutes(10)); // Store country for 10 minutes
            $this->sendMessage($chatId, 'Great! Now, please enter the recipient’s phone number with the country code. 📱', $messageId);
            Cache::put("airtime_step_{$chatId}", 'phone'); // Move to phone number step
            return;
        }

        // Ask for phone number if in the phone step
        if (Cache::get("airtime_step_{$chatId}") === 'phone') {
            Cache::put("airtime_phone_{$chatId}", $message, now()->addMinutes(10)); // Store phone number for 10 minutes
            $this->sendMessage($chatId, 'Processing your airtime request for ' . Cache::get("airtime_phone_{$chatId}") . ' in ' . Cache::get("airtime_country_{$chatId}") . '. Please hold on... ⏳', $messageId);
            $this->processAirtimeTransaction($chatId, $messageId); // Process the airtime transaction
            Cache::forget("airtime_mode_{$chatId}"); // Exit airtime mode after completion
            return;
        }
    } else {

       // If user is not in airtime mode, check for the "airtime" keyword
if (stripos($message, 'airtime') !== false) {
    Cache::put("airtime_mode_{$chatId}", true, now()->addMinutes(10)); // Activate airtime mode for 10 minutes
    $this->sendMessage($chatId, 'Nice! Tell me your Mirasub username. 😊', $messageId);
    return;
}
    }

    // Handle other bot logic if not in airtime mode
    $response = $this->getResponse($message);
  //  $this->sendTypingAction($chatId);
    $this->sendMessage($chatId, $response, $messageId);





        }

        // Log the interaction
        $this->logChat($message, $response ?? 'Image generated', $username);
    }
// Validate credentials using Laravel's Hash and the users table
private function validateCredentials($username, $password)
{
    // Attempt to find the user by username
    $user = DB::table('users')->where('username', $username)->first();

    if ($user && Hash::check($password, $user->password)) {
        // Credentials are correct
        return true;
    }

    // Credentials are incorrect
    return false;
}

// Placeholder for airtime transaction processing
private function processAirtimeTransaction($chatId, $messageId)
{
    // Simulate a delay or async airtime processing (can be replaced with actual API call)
    sleep(2); // Simulate processing time
    $this->sendMessage($chatId, "Airtime has been successfully sent to " . Cache::get("airtime_phone_{$chatId}") . " in " . Cache::get("airtime_country_{$chatId}") . "! 🎉", $messageId);

    // Clear airtime session after processing
    Cache::forget("airtime_username_{$chatId}");
    Cache::forget("airtime_password_{$chatId}");
    Cache::forget("airtime_country_{$chatId}");
    Cache::forget("airtime_phone_{$chatId}");
    Cache::forget("airtime_passkey_{$chatId}");
}
private function fetchImageFromUnsplash($query, $retryCount = 0)
{
    $unsplashApiUrl = 'https://api.unsplash.com/search/photos';
    $apiKeys = [
        env('UNSPLASH_API_KEY_1'),
        env('UNSPLASH_API_KEY_2'),
        env('UNSPLASH_API_KEY_3'),
    ];

    $maxRetries = count($apiKeys);
    $apiKey = $apiKeys[$retryCount % $maxRetries];

    // Request more than one image (e.g., x images) to get a random one
    $response = Http::get($unsplashApiUrl, [
        'query' => $query,
        'client_id' => $apiKey,
        'per_page' => 3, // Fetch multiple images to randomize
    ]);

    if ($response->status() == 403) {
        $retryAfter = $response->header('X-Ratelimit-Reset'); // Get rate limit reset time
        $waitTime = max(0, $retryAfter - time());

        if ($retryCount < $maxRetries - 1) {
            sleep($waitTime); // Wait for rate limit to reset
            return $this->fetchImageFromUnsplash($query, $retryCount + 1); // Retry with the next API key
        } else {
            return response()->json([
                'message' => 'Rate limit exceeded for all API keys. Please try again later.',
                'retry_after' => $waitTime,
            ], 429);
        }
    }

    if ($response->successful() && isset($response['results']) && count($response['results']) > 0) {
        // Randomly select one image from the results
        $randomImage = $response['results'][array_rand($response['results'])]['urls']['regular'];
        return $randomImage;
    }

    return null; // Return null if no image is found or other error occurs
}


    public function sendImage($chatId, $imageUrl, $messageId)
    {
        // Fetch the token directly from the environment variable
        $telegramBotToken = env('TELEGRAM_BOT_TOKEN');
    
        // URL to send messages
        $messageUrl = "https://api.telegram.org/bot$telegramBotToken/sendMessage";
    
        // Send an automated "Generating, Please hold on" message
        $automatedMessagePayload = [
            'chat_id' => $chatId,
            'text' => 'Generating, Please hold on...',
            'reply_to_message_id' => $messageId,
        ];
    
        // Send the automated message request synchronously
        $automatedMessageResponse = Http::post($messageUrl, $automatedMessagePayload);
    
        // Check if the message was successfully sent before sending the image
        if (!$automatedMessageResponse->successful()) {
            throw new \Exception('Failed to send automated message: ' . $automatedMessageResponse->body());
        }
    
        // Now send the image
        $photoUrl = "https://api.telegram.org/bot$telegramBotToken/sendPhoto";
    
        // Prepare the payload for sending the image
        $photoPayload = [
            'chat_id' => $chatId,
            'photo' => $imageUrl,
            'reply_to_message_id' => $messageId,
        ];
    
        // Send the request to send the image
        $response = Http::post($photoUrl, $photoPayload);
    
        // Handle the response (optional)
        if ($response->successful()) {
            return $response->json();
        } else {
            // Handle error here, maybe log it or return a custom message
            throw new \Exception('Telegram API request failed: ' . $response->body());
        }
    }
    
    private function sendTypingAction($chatId) 
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/sendChatAction";
    
        $data = [
            'chat_id' => $chatId,
            'action' => 'typing',
        ];
    
        try {
            Http::post($url, $data);
        } catch (\Exception $e) {
            Log::error('Error sending typing action: ' . $e->getMessage());
        }
    }
    


    private function checkWordLimit($message, $limit = 6)
    {
        // Split the message into an array of words
        $words = preg_split('/\s+/', $message);

        // Check if the number of words exceeds the limit
        if (count($words) > $limit) {
            return true;
        }

        return false;
    }

    private function logChat($userMessage, $botResponse, $username = 'unknown')
    {
        $timestamp = now()->toDateTimeString();

        Log::channel('chat')->info('Chat Interaction', [
            'timestamp' => $timestamp,
            'username' => $username, // Log the username
            'user_message' => $userMessage,
            'bot_response' => $botResponse,
        ]);
    }

    private function containsOnlyEmoji($message)
    {
        // Remove all emoji characters from the message
        $cleanMessage = preg_replace('/[\p{So}\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}]/u', '', $message);

        // If the cleaned message is empty, it means the original message was only emojis
        return trim($cleanMessage) === '';
    }
    private function containsViolentKeywords($message)
    {

        $filePath = base_path('resources/data/bad.txt');

        if (!file_exists($filePath)) {
            return false;
        }

        // Read the keywords from the file into an array
        $violentKeywords = array_map('trim', file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        $normalizedMessage = strtolower($message);

        foreach ($violentKeywords as $keyword) {
            // Using a whole-word matching pattern
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $normalizedMessage)) {
                return true;
            }
        }

        // No violent keywords found
        return false;
    }

    private function getResponse($message)
    {
        $message = strtolower($message);

        // Check if the message exceeds the word limit
        if ($this->checkWordLimit($message)) {
            return "Oops, looks like you've used more than six words. Please keep it short and sweet! 😊";
        }

        // Check if the message contains only emojis
        if ($this->containsOnlyEmoji($message)) {
            return "Hmm, it looks like you're sending only emojis. Can you add some text too? 😅";
        }

        // Check if the message contains violent keywords
        if ($this->containsViolentKeywords($message)) {
            return "Whoa there! 😳 Let's keep things positive, no need for negetive language. Peace and love! ✌️";
        }

        if ($this->isCalculationRequest($message)) {
            $expression = $this->extractMathExpression($message);
            return $this->calculate($expression);
        }

        if (strpos($message, 'news') !== false) {
            if (strpos($message, 'nigeria') !== false) {
                return $this->fetchLatestRssNews('nigeria');
            }
            return $this->fetchLatestRssNews('global');
        }

        $messages = require base_path('resources/data/messages.php');
        $responses = require base_path('resources/data/responses.php');

        foreach ($messages as $tag => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($message, $keyword) !== false) {
                    if ($tag === 'q16') {
                        return $this->getJokes();
                    }
                    if (isset($responses[$tag])) {
                        $responsesForTag = $responses[$tag];
                        $response = $responsesForTag[array_rand($responsesForTag)];
                        return $response . ' ' . $this->getRandomEmoji();
                    }
                }
            }
        }

        // Check for "how to" queries
        if (preg_match('/^how to/', $message)) {
            return $this->searchWikiHow($message);
        }

        // If no match found, search Wikipedia
        return $this->searchWikipedia($message);
    }

    private function isCalculationRequest($message)
    {
        return preg_match('/plus|minus|times|divided by|\+|\-|\*|\//', $message);
    }

    private function extractMathExpression($message)
    {
        if (preg_match('/(\d+|\b(?:one|two|three|four|five|six|seven|eight|nine|ten)\b).*?(\+|\-|\*|\/).*?(\d+)/', $message, $matches)) {
            return $matches[0];
        }
        return $message;
    }

    private function calculate($message)
    {
        // Check if the message starts with "/start"
        if (strpos($message, '/start') === 0) {
            // If it does, return the welcome message from the file
            $welcomeMessagePath = base_path('resources/data/welcome_message.txt');
            if (file_exists($welcomeMessagePath)) {
                $welcomeMessage = file_get_contents($welcomeMessagePath);
                return $welcomeMessage ?: "Welcome! How can I assist you today?";
            } else {
                Log::error("Welcome message file not found at: " . $welcomeMessagePath);
                return "Welcome! How can I assist you today?";
            }
        }
        if (strpos($message, '/about') === 0) {
            // If it does, return the about message from the file
            $aboutMessagePath = base_path('resources/data/about_message.txt');
            if (file_exists($aboutMessagePath)) {
                $aboutMessage = file_get_contents($aboutMessagePath);
                return $aboutMessage ?: "I can guide and help you buy from our website directly from Telegram";
            } else {
                Log::error("About message file not found at: " . $aboutMessagePath);
                return "I can guide and help you buy from our website directly from Telegram";
            }
        }
        if (strpos($message, '/help') === 0) {
            // If it does, return the help message from the file
            $helpMessagePath = base_path('resources/data/help_message.txt');
            if (file_exists($helpMessagePath)) {
                $helpMessage = file_get_contents($helpMessagePath);
                return $helpMessage ?: "I can guide and help you buy from our website directly from Telegram";
            } else {
                Log::error("help message file not found at: " . $helpMessagePath);
                return "I can guide and help you buy from our website directly from Telegram";
            }
        }

        // The rest of your existing function code remains the same
        $message = str_replace(['plus', 'minus', 'times', 'divided by'], ['+', '-', '*', '/'], $message);
        foreach ($this->numberWords as $word => $num) {
            $message = str_replace($word, $num, $message);
        }
        try {
            $executor = new MathExecutor();
            $result = $executor->execute($message);
            return "The result is: $result";
        } catch (\Exception $e) {
            Log::error("Calculation error: " . $e->getMessage());
            return "Sorry, I couldn't calculate that.";
        }
    }

    private function fetchLatestRssNews($category = 'global')
    {
        $feeds = $this->rssFeeds[$category] ?? $this->rssFeeds['global'];
        $randomFeed = $feeds[array_rand($feeds)];

        try {
            $rss = simplexml_load_file($randomFeed);
            $headlines = [];
            foreach ($rss->channel->item as $item) {
                $headlines[] = (string) $item->title;
                if (count($headlines) >= 10) {
                    break;
                }

            }

            if (!empty($headlines)) {
                $responseText = "Here are the top headlines as of now:\n\n";
                foreach ($headlines as $headline) {
                    $responseText .= "- " . $headline . "\n";
                }
                return $responseText;
            } else {
                return "Sorry, I couldn't find any news at the moment.";
            }
        } catch (\Exception $e) {
            Log::error("Error fetching RSS news: " . $e->getMessage());
            return "There was an issue fetching the news.";
        }
    }

    private function getRandomEmoji()
    {
        return $this->emojis[array_rand($this->emojis)];
    }

    private function searchWikipedia($query)
    {
        $searchQuery = $this->cleanSearchQuery($query);
        $cacheKey = 'wikipedia_' . md5($searchQuery);

        // Check cache first
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Implement rate limiting
        if (!$this->checkRateLimit('wikipedia', 60, 10)) { // 10 requests per minute
            return "I'm sorry, but I'm receiving too many requests right now. Please try again in a minute.";
        }

        $apiUrl = "https://en.wikipedia.org/w/api.php?format=json&action=query&prop=extracts&exintro&explaintext&redirects=1&titles=" . urlencode($searchQuery);

        try {
            $response = Http::get($apiUrl);
            $data = $response->json();

            if (isset($data['query']['pages'])) {
                $page = reset($data['query']['pages']);
                if (isset($page['extract'])) {
                    $summary = $page['extract'];
                    $summary = $this->truncateSummary($summary);
                    $result = "Here's what I found about '$searchQuery':\n\n" . $summary . "\n\nThis information is from Wikipedia." . ' ' . $this->getRandomEmoji();

                    // Cache the result for 1 hour
                    Cache::put($cacheKey, $result, 3600);

                    return $result;
                }
            }

            Log::info("No Wikipedia results found for query: $searchQuery");
            return "I couldn't find any info about that. In my coming updates, I would be able to use other alteratives. Can you try rephrasing your question?";
        } catch (\Exception $e) {
            Log::error("Error in Wikipedia search: " . $e->getMessage());
            return "I'm having trouble accessing Wikipedia right now. Please try again later.";
        }
    }

    private function searchWikiHow($query)
    {
        $searchQuery = $this->cleanSearchQuery($query);
        $cacheKey = 'wikihow_' . md5($searchQuery);

        // Check cache first
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Rate limiting
        if (!$this->checkRateLimit('wikihow', 60, 10)) {
            return "I'm receiving too many requests right now. Please try again in a minute.";
        }

        $apiUrl = "https://www.wikihow.com/api.php?action=query&format=json&list=search&utf8=1&srsearch=" . urlencode($searchQuery);

        try {
            $response = Http::get($apiUrl);
            $data = $response->json();

            if (isset($data['query']['search'][0]['title'])) {
                $articleTitle = $data['query']['search'][0]['title'];
                $articleUrl = "https://www.wikihow.com/" . str_replace(' ', '-', $articleTitle);

                $articleContent = Http::get($articleUrl)->body();

                Log::info("Fetched wikiHow article HTML for debugging", [$articleContent]);

                $crawler = new Crawler($articleContent);

                $steps = $crawler->filter('div.steps li')->each(function (Crawler $node, $i) {
                    return ($i + 1) . '. ' . trim($node->filter('div.step')->text());
                });

                $tips = $crawler->filter('div.tips ul li')->each(function (Crawler $node, $i) {
                    return 'Tip ' . ($i + 1) . ': ' . trim($node->text());
                });

                $warnings = $crawler->filter('div.section.warnings .section_text ul li .warnings_li_div')->each(function (Crawler $node, $i) {
                    return 'Warning ' . ($i + 1) . ': ' . trim($node->text());
                });

                if (empty($steps)) {
                    Log::info("No steps found in wikiHow article: " . $articleUrl);
                    return "I found the article, but couldn't extract any steps.";
                }

                $formattedSteps = implode("\n", array_slice($steps, 0, 4));
                $formattedTips = !empty($tips) ? "Additional Tips:\n" . implode("\n", $tips) : '';
                $formattedWarnings = !empty($warnings) ? "Warnings:\n" . implode("\n", $warnings) : '';

                $result = "Here's how to $searchQuery:\n\n$formattedSteps\n\n$formattedTips\n\n$formattedWarnings\n" .
                "You can also check the full guide here: $articleUrl\n" .
                "This info is from wikiHow. Hope that helps!" . ' ' . $this->getRandomEmoji();

                Cache::put($cacheKey, $result, 3600);

                return $result;
            } else {
                Log::info("No wikiHow results found for query: $searchQuery");
                return "I couldn't find any wikiHow articles about that. Please try rephrasing your question.";
            }
        } catch (\Exception $e) {
            Log::error("Error in wikiHow search: " . $e->getMessage());
            return "I'm having trouble accessing wikiHow right now. Please try again later.";
        }
    }

    private function getJokes()
    {
        try {
            $response = Http::get('https://official-joke-api.appspot.com/jokes/random/5');
            $jokes = $response->json();

            if (!empty($jokes)) {
                $formattedJokes = "Here are some jokes for you:\n\n";
                foreach ($jokes as $joke) {
                    $expert = $joke['setup'] ?? 'N/A';
                    $legend = $joke['punchline'] ?? 'N/A';
                    $formattedJokes .= "🤣 *Expert:* $expert\n🤔 *Legend:* $legend\n\n";
                }
                return $formattedJokes . $this->getRandomEmoji();
            } else {
                return "Sorry, I couldn't fetch any jokes at the moment. " . $this->getRandomEmoji();
            }
        } catch (\Exception $e) {
            Log::error("Error fetching jokes: " . $e->getMessage());
            return "I'm having trouble fetching jokes right now. Please try again later. " . $this->getRandomEmoji();
        }
    }

    private function cleanSearchQuery($query)
    {
        $query = strtolower($query);

        $patterns = [
            '/^what is /',
            '/^what\'s /',
            '/^how is /',
            '/^what are /',
            '/^what\'re /',
            '/^who is /',
            '/^where is /',
            '/^when is /',
            '/^why is /',
            '/^tell me about /',
            '/^how to /',
            '/^how do you /',
        ];
        $query = preg_replace($patterns, '', $query);

        $query = preg_replace('/\b(the|a|an|in|on|at|to|for|of|with|by)\b/', '', $query);

        return trim(preg_replace('/\s+/', ' ', $query));
    }

    private function truncateSummary($summary, $maxLength = 1000)
    {
        if (strlen($summary) > $maxLength) {
            return substr($summary, 0, $maxLength) . '...';
        }
        return $summary;
    }

    private function checkRateLimit($service, $timeFrameInSeconds, $maxRequests)
    {
        $cacheKey = $service . '_rate_limit_' . auth()->id();
        $requests = Cache::get($cacheKey, []);

        $now = now()->timestamp;
        $requests = array_filter($requests, function ($timestamp) use ($now, $timeFrameInSeconds) {
            return ($now - $timestamp) < $timeFrameInSeconds;
        });

        if (count($requests) >= $maxRequests) {
            return false;
        }

        $requests[] = $now;
        Cache::put($cacheKey, $requests, $timeFrameInSeconds);

        return true;
    }

    private function sendMessage($chatId, $message, $replyToMessageId = null)
    {
        // Send typing action before sending the message
        $this->sendTypingAction($chatId);
    
        // Replace words in the message
        $message = $this->replaceWords($message);
    
        // Prepare data to send the message
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown', // You can also use 'HTML' depending on your formatting preference
        ];
    
        // If reply_to_message_id is provided, include it in the data
        if ($replyToMessageId) {
            $data['reply_to_message_id'] = $replyToMessageId;
        }
    
        // Send the message via the Telegram API
        try {
            $botToken = env('TELEGRAM_BOT_TOKEN');
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            Http::post($url, $data);
        } catch (\Exception $e) {
            Log::error('Error sending message to Telegram: ' . $e->getMessage());
        }
    }
    
    private function replaceWords($message)
{
    // Get the word mappings from the cache
    $wordMappings = $this->getWordMappings();

    // Create a regular expression pattern to match all the words in the mappings
    $pattern = '/' . implode('|', array_map('preg_quote', array_keys($wordMappings))) . '/i';

    // Define a callback function to handle the replacements
    $message = preg_replace_callback($pattern, function ($matches) use ($wordMappings) {
        $word = strtolower($matches[0]); // Get the matched word
        if (isset($wordMappings[$word])) {
            $alternatives = explode(',', $wordMappings[$word]);
            return trim($alternatives[array_rand($alternatives)]); // Replace with a random alternative
        }
        return $matches[0]; // Return the original word if no replacement is found
    }, $message);

    return $message;
}

    
    private function getWordMappings()
{
    // Cache the word mappings indefinitely
    return Cache::rememberForever('word_mappings', function () {
        $filePath = base_path('resources/data/rewrite.txt');

        if (!file_exists($filePath)) {
            Log::error('Words file not found.');
            return [];
        }

        $wordMappings = [];
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) == 2) {
                $wordMappings[$parts[0]] = $parts[1];
            }
        }

        return $wordMappings;
    });
}

    


}
