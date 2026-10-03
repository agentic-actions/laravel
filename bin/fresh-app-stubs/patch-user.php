<?php

/*
 * Add a posts() relation to the new app's User model, and with --api-tokens Sanctum's HasApiTokens trait, which
 * php artisan install:api asks the developer to add. Run from the app's base path: php patch-user.php [--api-tokens]
 */

$path = 'app/Models/User.php';
$source = file_get_contents($path);

if ($source === false || ! str_contains($source, 'class User extends')) {
    fwrite(STDERR, "{$path} is not the User model this script knows.\n");

    exit(1);
}

$imports = ['Illuminate\\Database\\Eloquent\\Relations\\HasMany'];
$traits = [];

if (in_array('--api-tokens', $argv, true)) {
    $imports[] = 'Laravel\\Sanctum\\HasApiTokens';
    $traits[] = 'HasApiTokens';
}

foreach ($imports as $import) {
    if (! str_contains($source, "use {$import};")) {
        $source = preg_replace('/^namespace App\\\\Models;\n/m', "namespace App\\Models;\n\nuse {$import};", $source, 1);
    }
}

foreach ($traits as $trait) {
    if (! preg_match('/^[ \t]+use [^;]*\b'.$trait.'\b/m', $source)) {
        $source = preg_replace('/^([ \t]+)use HasFactory\b/m', "\$1use {$trait}, HasFactory", $source, 1);
    }
}

if (! str_contains($source, 'function posts(')) {
    $relation = <<<'PHP'

    /**
     * Get the posts the user wrote.
     *
     * @return HasMany<\App\Models\Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
PHP;

    $source = preg_replace('/\n}\s*$/', "\n".$relation."\n", $source, 1);
}

file_put_contents($path, $source);
