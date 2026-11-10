package br.com.esis.garcom.ui.menu

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedIconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.esis.garcom.R
import br.com.esis.garcom.data.MenuProduct
import br.com.esis.garcom.ui.floor.ErrorBanner
import br.com.esis.garcom.ui.floor.money
import br.com.esis.garcom.ui.order.BackHeader

@Composable
fun MenuScreen(
    viewModel: MenuViewModel,
    tableNumber: Int,
    onBack: () -> Unit,
    onSent: () -> Unit,
    onClosed: (String) -> Unit,
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    var confirmDiscard by rememberSaveable { mutableStateOf(false) }
    val leave = { if (state.cart.lines.isEmpty()) onBack() else confirmDiscard = true }

    BackHandler(onBack = leave)
    LaunchedEffect(state.sent) {
        if (state.sent) {
            viewModel.acknowledgeSent()
            onSent()
        }
    }
    LaunchedEffect(state.closed) { state.closed?.let(onClosed) }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BackHeader(title = "Adicionar itens", subtitle = "Mesa $tableNumber", onBack = leave)

        OutlinedTextField(
            value = state.query,
            onValueChange = viewModel::search,
            placeholder = { Text("Buscar no cardápio") },
            leadingIcon = { Icon(painterResource(R.drawable.ic_search), contentDescription = null, modifier = Modifier.size(20.dp)) },
            singleLine = true,
            shape = RoundedCornerShape(14.dp),
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
        )

        CategoryChips(state, onSelect = viewModel::selectCategory)

        state.error?.let { message ->
            ErrorBanner(message, onRetry = if (state.products.isEmpty()) viewModel::load else viewModel::send)
        }

        Box(Modifier.weight(1f)) {
            when {
                state.loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                state.visibleProducts.isEmpty() && state.products.isNotEmpty() -> Text(
                    "Nada encontrado para \"${state.query}\".",
                    textAlign = TextAlign.Center,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.fillMaxWidth().padding(24.dp),
                )
                else -> LazyColumn(contentPadding = PaddingValues(horizontal = 16.dp, vertical = 4.dp)) {
                    items(state.visibleProducts, key = { it.id }) { product ->
                        ProductRow(
                            product = product,
                            quantity = state.cart.quantityOf(product.id),
                            onAdd = { viewModel.tapAdd(product) },
                            onRemove = { viewModel.tapRemove(product) },
                        )
                        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                    }
                }
            }
        }

        SendBar(state, onSend = viewModel::send)
    }

    state.sheet?.let { sheet -> ProductSheet(sheet, onAdd = viewModel::addComposed, onDismiss = viewModel::closeSheet) }

    if (confirmDiscard) {
        AlertDialog(
            onDismissRequest = { confirmDiscard = false },
            title = { Text("Descartar os itens?") },
            text = { Text("Os itens escolhidos ainda não foram enviados para a cozinha.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmDiscard = false
                    viewModel.discardCart()
                    onBack()
                }) { Text("Descartar", color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.Bold) }
            },
            dismissButton = { TextButton(onClick = { confirmDiscard = false }) { Text("Continuar escolhendo") } },
        )
    }
}

@Composable
private fun CategoryChips(state: MenuUi, onSelect: (Long?) -> Unit) {
    if (state.categories.isEmpty()) {
        return
    }
    Row(
        modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 12.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Chip("Todas", selected = state.selectedCategory == null) { onSelect(null) }
        state.categories.forEach { category ->
            Chip(category.name, selected = state.selectedCategory == category.id) { onSelect(category.id) }
        }
    }
}

@Composable
private fun Chip(label: String, selected: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        modifier = Modifier.height(44.dp).semantics {
            role = Role.Tab
            this.selected = selected
        },
        shape = CircleShape,
        color = if (selected) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.surface,
        contentColor = if (selected) MaterialTheme.colorScheme.surface else MaterialTheme.colorScheme.onSurface,
        border = if (selected) null else BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
    ) {
        Box(Modifier.padding(horizontal = 16.dp), contentAlignment = Alignment.Center) {
            Text(label, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun ProductRow(product: MenuProduct, quantity: Int, onAdd: () -> Unit, onRemove: () -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(product.name, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
            val price = if (product.fromPrice != product.price) {
                "a partir de ${money(product.fromPrice) ?: product.fromPrice}"
            } else {
                money(product.price) ?: product.price
            }
            Text(price, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            if (quantity > 0) {
                OutlinedIconButton(onClick = onRemove, modifier = Modifier.size(44.dp)) {
                    Icon(painterResource(R.drawable.ic_minus), contentDescription = "Tirar um ${product.name}", modifier = Modifier.size(20.dp))
                }
                Text(
                    quantity.toString(),
                    fontSize = 17.sp,
                    fontWeight = FontWeight.Bold,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.width(36.dp),
                )
            }
            OutlinedIconButton(
                onClick = onAdd,
                modifier = Modifier.size(44.dp),
                border = BorderStroke(1.dp, MaterialTheme.colorScheme.primary),
            ) {
                Icon(
                    painterResource(R.drawable.ic_plus),
                    contentDescription = "Adicionar ${product.name}",
                    tint = MaterialTheme.colorScheme.primary,
                    modifier = Modifier.size(20.dp),
                )
            }
        }
    }
}

@Composable
private fun SendBar(state: MenuUi, onSend: () -> Unit) {
    Surface(color = MaterialTheme.colorScheme.surface, shadowElevation = 8.dp) {
        Button(
            onClick = onSend,
            enabled = state.cart.count > 0 && !state.sending,
            shape = RoundedCornerShape(14.dp),
            modifier = Modifier.fillMaxWidth().padding(16.dp).height(56.dp),
        ) {
            if (state.sending) {
                CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
            } else {
                Text("Enviar para a cozinha", fontSize = 17.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                if (state.cart.count > 0) {
                    Text(
                        "${state.cart.count} · ${money(state.cart.total) ?: state.cart.total}",
                        fontSize = 15.sp,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            }
        }
    }
}
