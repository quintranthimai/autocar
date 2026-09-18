<?php
$u = App\Models\User::where('email', 'admin@autocar.vn')->first();
$u->password = Hash::make('password123');
$u->save();
echo "Roles: " . $u->roles->pluck('slug')->implode(', ') . "\n";
echo "Reset OK";
