package br.com.esis.garcom.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.ExperimentalTextApi
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontVariation
import androidx.compose.ui.text.font.FontWeight
import br.com.esis.garcom.R

/** Laranja da marca: só no botão principal e na mesa ocupada, para chamar atenção. */
val Brand = Color(0xFFEA580C)

private val LightColors = lightColorScheme(
    primary = Brand,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFFFEDD5),
    onPrimaryContainer = Color(0xFF7C2D12),
    background = Color(0xFFF5F5F4),
    onBackground = Color(0xFF1C1917),
    surface = Color.White,
    onSurface = Color(0xFF1C1917),
    onSurfaceVariant = Color(0xFF57534E),
    outline = Color(0xFFD6D3D1),
    outlineVariant = Color(0xFFE7E5E4),
    error = Color(0xFFB91C1C),
    errorContainer = Color(0xFFFEF2F2),
    onErrorContainer = Color(0xFF991B1B),
)

private val DarkColors = darkColorScheme(
    primary = Brand,
    onPrimary = Color.White,
    primaryContainer = Color(0xFF431407),
    onPrimaryContainer = Color(0xFFFED7AA),
    background = Color(0xFF0C0A09),
    onBackground = Color(0xFFF5F5F4),
    surface = Color(0xFF1C1917),
    onSurface = Color(0xFFF5F5F4),
    onSurfaceVariant = Color(0xFFA8A29E),
    outline = Color(0xFF57534E),
    outlineVariant = Color(0xFF3A3532),
    error = Color(0xFFF87171),
    errorContainer = Color(0xFF450A0A),
    onErrorContainer = Color(0xFFFECACA),
)

@OptIn(ExperimentalTextApi::class)
private fun inter(weight: Int): Font =
    Font(R.font.inter, FontWeight(weight), variationSettings = FontVariation.Settings(FontVariation.weight(weight)))

val Inter = FontFamily(inter(400), inter(500), inter(600), inter(700), inter(800))

private fun TextStyle.inter(): TextStyle = copy(fontFamily = Inter)

private val AppTypography = Typography().run {
    Typography(
        displayLarge = displayLarge.inter(),
        displayMedium = displayMedium.inter(),
        displaySmall = displaySmall.inter(),
        headlineLarge = headlineLarge.inter(),
        headlineMedium = headlineMedium.inter(),
        headlineSmall = headlineSmall.inter(),
        titleLarge = titleLarge.inter(),
        titleMedium = titleMedium.inter(),
        titleSmall = titleSmall.inter(),
        bodyLarge = bodyLarge.inter(),
        bodyMedium = bodyMedium.inter(),
        bodySmall = bodySmall.inter(),
        labelLarge = labelLarge.inter(),
        labelMedium = labelMedium.inter(),
        labelSmall = labelSmall.inter(),
    )
}

/** Se o app está no tema escuro. As telas leem daqui, não do celular, para seguir a escolha do app. */
val LocalDarkTheme = staticCompositionLocalOf { false }

/** O app abre sempre no tema claro; o escuro fica pronto para quando houver a opção nas configurações. */
@Composable
fun GarcomTheme(darkTheme: Boolean = false, content: @Composable () -> Unit) {
    CompositionLocalProvider(LocalDarkTheme provides darkTheme) {
        MaterialTheme(
            colorScheme = if (darkTheme) DarkColors else LightColors,
            typography = AppTypography,
            content = content,
        )
    }
}
