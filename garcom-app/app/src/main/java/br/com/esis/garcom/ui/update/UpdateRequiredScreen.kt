package br.com.esis.garcom.ui.update

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import br.com.esis.garcom.BuildConfig

/** O servidor exige uma versão mais nova do app: só resta atualizar pela Play Store. */
@Composable
fun UpdateRequiredScreen() {
    val context = LocalContext.current
    val storeUrl = "https://play.google.com/store/apps/details?id=${BuildConfig.APPLICATION_ID}"

    Column(
        modifier = Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text("Atualize o app", fontSize = 24.sp, fontWeight = FontWeight.ExtraBold)
        Text(
            "Esta versão do app do garçom não funciona mais com o sistema do restaurante. Atualize pela Play Store para continuar.",
            textAlign = TextAlign.Center,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Button(
            onClick = {
                val intent = Intent(Intent.ACTION_VIEW, Uri.parse(storeUrl)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                try {
                    context.startActivity(intent)
                } catch (error: ActivityNotFoundException) {
                    // Celular sem loja nem navegador: nada a abrir.
                }
            },
            shape = RoundedCornerShape(14.dp),
            modifier = Modifier.fillMaxWidth().height(56.dp),
        ) {
            Text("Atualizar na Play Store", fontSize = 17.sp, fontWeight = FontWeight.Bold)
        }
    }
}
