package br.com.esis.garcom.ui.order

import androidx.compose.foundation.background
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
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedIconButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import br.com.esis.garcom.R
import br.com.esis.garcom.data.ItemStatus
import br.com.esis.garcom.data.OrderItem
import br.com.esis.garcom.data.TableOrder
import br.com.esis.garcom.ui.floor.ErrorBanner
import br.com.esis.garcom.ui.floor.money
import kotlinx.coroutines.delay

private const val REFRESH_INTERVAL_MS = 10_000L

@Composable
fun OrderScreen(
    viewModel: OrderViewModel,
    tableNumber: Int,
    onBack: () -> Unit,
    onClosed: (String) -> Unit,
    onAddItems: (orderId: Long) -> Unit,
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val lifecycleOwner = LocalLifecycleOwner.current

    LaunchedEffect(lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            while (true) {
                viewModel.refresh()
                delay(REFRESH_INTERVAL_MS)
            }
        }
    }
    LaunchedEffect(state) {
        (state as? OrderState.Closed)?.let { onClosed(it.message) }
    }

    val order = (state as? OrderState.Showing)?.order

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BackHeader(
            title = "Mesa $tableNumber",
            subtitle = order?.let(::orderSubtitle),
            onBack = onBack,
        )

        when (val current = state) {
            is OrderState.Showing -> {
                current.error?.let { ErrorBanner(it, onRetry = viewModel::retry) }
                OrderBody(current.order, modifier = Modifier.weight(1f))
                Footer(current.order, onAddItems = { onAddItems(current.order.id) })
            }
            is OrderState.Loading -> {
                current.error?.let { ErrorBanner(it, onRetry = viewModel::retry) }
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            }
            is OrderState.AskPeople -> PeopleDialog(current, onOpen = viewModel::open, onDismiss = onBack)
            is OrderState.Closed -> Unit
        }
    }
}

private fun orderSubtitle(order: TableOrder): String = listOfNotNull(
    if (order.people == 1) "1 pessoa" else "${order.people} pessoas",
    "${order.minutes} min",
    order.waiter,
).joinToString(" · ")

@Composable
fun BackHeader(title: String, subtitle: String?, onBack: () -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(start = 4.dp, end = 16.dp, top = 8.dp, bottom = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onBack, modifier = Modifier.size(48.dp)) {
            Icon(painterResource(R.drawable.ic_chevron_left), contentDescription = "Voltar", modifier = Modifier.size(24.dp))
        }
        Column {
            Text(title, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
            subtitle?.let { Text(it, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        }
    }
}

@Composable
private fun OrderBody(order: TableOrder, modifier: Modifier) {
    if (order.items.isEmpty()) {
        Box(modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
            Text(
                "Nenhum item ainda.\nToque em Adicionar itens para lançar o pedido.",
                textAlign = TextAlign.Center,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        return
    }
    LazyColumn(modifier = modifier.fillMaxWidth(), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 4.dp)) {
        items(order.items, key = { it.id }) { item ->
            ItemRow(item)
            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
        }
    }
}

@Composable
private fun ItemRow(item: OrderItem) {
    val cancelled = item.status == ItemStatus.CANCELLED
    val decoration = if (cancelled) TextDecoration.LineThrough else null
    val faded = if (cancelled) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface

    Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(
            "${quantityLabel(item.quantity)}×",
            fontSize = 16.sp,
            fontWeight = FontWeight.Bold,
            color = faded,
            modifier = Modifier.width(36.dp),
        )
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(item.name, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = faded, textDecoration = decoration)
            item.optionsLabel?.let { Text(it, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, textDecoration = decoration) }
            item.notes?.let { Text(it, fontSize = 13.sp, fontStyle = FontStyle.Italic, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            item.status.style()?.let { StatusBadge(it) }
        }
        Text(
            money(item.total) ?: item.total,
            fontSize = 15.sp,
            fontWeight = FontWeight.SemiBold,
            color = faded,
            textDecoration = decoration,
        )
    }
}

@Composable
private fun StatusBadge(style: ItemStatusStyle) {
    Surface(shape = RoundedCornerShape(999.dp), color = style.container, contentColor = style.content, modifier = Modifier.padding(top = 2.dp)) {
        Text(style.label, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp))
    }
}

@Composable
private fun Footer(order: TableOrder, onAddItems: () -> Unit) {
    Surface(color = MaterialTheme.colorScheme.surface, shadowElevation = 8.dp) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("Total da mesa", fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
                Text(money(order.total) ?: order.total, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
            }
            Button(onClick = onAddItems, shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth().height(56.dp)) {
                Icon(painterResource(R.drawable.ic_plus), contentDescription = null, modifier = Modifier.size(20.dp))
                Text("Adicionar itens", fontSize = 17.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 8.dp))
            }
        }
    }
}

@Composable
private fun PeopleDialog(state: OrderState.AskPeople, onOpen: (Int) -> Unit, onDismiss: () -> Unit) {
    var people by rememberSaveable { mutableIntStateOf(2) }

    AlertDialog(
        onDismissRequest = { if (!state.opening) onDismiss() },
        icon = { Icon(painterResource(R.drawable.ic_users), contentDescription = null) },
        title = { Text("Quantas pessoas?") },
        text = {
            Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                    OutlinedIconButton(onClick = { people-- }, enabled = people > 1 && !state.opening, modifier = Modifier.size(52.dp)) {
                        Icon(painterResource(R.drawable.ic_minus), contentDescription = "Menos uma pessoa")
                    }
                    Text(
                        people.toString(),
                        fontSize = 34.sp,
                        fontWeight = FontWeight.ExtraBold,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.width(56.dp).semantics { liveRegion = LiveRegionMode.Polite },
                    )
                    OutlinedIconButton(onClick = { people++ }, enabled = people < 100 && !state.opening, modifier = Modifier.size(52.dp)) {
                        Icon(painterResource(R.drawable.ic_plus), contentDescription = "Mais uma pessoa")
                    }
                }
                state.error?.let {
                    Text(it, color = MaterialTheme.colorScheme.error, fontSize = 14.sp, textAlign = TextAlign.Center)
                }
            }
        },
        confirmButton = {
            Button(onClick = { onOpen(people) }, enabled = !state.opening, modifier = Modifier.height(48.dp)) {
                if (state.opening) {
                    CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                } else {
                    Text("Abrir mesa", fontWeight = FontWeight.Bold)
                }
            }
        },
        dismissButton = { TextButton(onClick = onDismiss, enabled = !state.opening) { Text("Cancelar") } },
    )
}
