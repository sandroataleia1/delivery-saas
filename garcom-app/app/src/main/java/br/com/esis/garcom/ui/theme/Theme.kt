package br.com.esis.garcom.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val Brand = Color(0xFFEA580C)

private val LightColors = lightColorScheme(primary = Brand, secondary = Color(0xFF0F766E))
private val DarkColors = darkColorScheme(primary = Color(0xFFFB923C), secondary = Color(0xFF2DD4BF))

@Composable
fun GarcomTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (isSystemInDarkTheme()) DarkColors else LightColors, content = content)
}
