package br.com.esis.garcom.ui.menu

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedIconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
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
import br.com.esis.garcom.R
import br.com.esis.garcom.data.CartLine
import br.com.esis.garcom.data.ChosenOption
import br.com.esis.garcom.data.MenuOption
import br.com.esis.garcom.data.OptionGroup
import br.com.esis.garcom.data.PizzaSize
import br.com.esis.garcom.ui.floor.money

private const val NOTES_MAX = 120

/** Monta o produto (tamanho, sabores, adicionais). O preço mostrado no carrinho vem da cotação do servidor. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ProductSheet(sheet: SheetUi, onAdd: (CartLine) -> Unit, onDismiss: () -> Unit) {
    val product = sheet.product
    val composition = product.composition ?: return
    val pizza = composition.pizza

    var sizeId by remember(product.id) { mutableStateOf(pizza?.sizes?.singleOrNull()?.id) }
    val flavors = remember(product.id) { mutableStateMapOf<Long, Boolean>() }
    val options = remember(product.id) { mutableStateMapOf<Long, Int>() }
    var notes by remember(product.id) { mutableStateOf("") }
    var quantity by remember(product.id) { mutableIntStateOf(1) }

    val size = pizza?.sizes?.firstOrNull { it.id == sizeId }
    val chosenFlavors = flavors.filterValues { it }.keys.sorted()
    val maxFlavors = size?.maxFlavors ?: 1
    val ready = (pizza == null || (size != null && chosenFlavors.isNotEmpty())) &&
        composition.groups.all { group -> group.chosenCount(options) >= group.min }

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
    ) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding()) {
            Text(
                product.name,
                fontSize = 22.sp,
                fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(horizontal = 20.dp),
            )

            Column(
                modifier = Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp),
                verticalArrangement = Arrangement.spacedBy(20.dp),
            ) {
                if (pizza != null) {
                    Section("Tamanho", "Escolha 1") {
                        pizza.sizes.forEach { option ->
                            SizeRow(option, selected = option.id == sizeId) {
                                sizeId = option.id
                                // Ao trocar para um tamanho menor, descarta os sabores que passaram do limite.
                                flavors.keys.filter { flavors[it] == true }.drop(option.maxFlavors).forEach { flavors.remove(it) }
                            }
                        }
                    }
                    Section("Sabores", if (maxFlavors == 1) "Escolha 1" else "Até $maxFlavors") {
                        pizza.flavors.forEach { flavor ->
                            val selected = flavors[flavor.id] == true
                            ChoiceRow(
                                option = flavor,
                                price = flavor.priceFor(sizeId),
                                selected = selected,
                                enabled = !flavor.paused && (selected || maxFlavors == 1 || chosenFlavors.size < maxFlavors),
                            ) {
                                if (maxFlavors == 1) {
                                    flavors.clear()
                                    flavors[flavor.id] = true
                                } else if (selected) {
                                    flavors.remove(flavor.id)
                                } else {
                                    flavors[flavor.id] = true
                                }
                            }
                        }
                    }
                }

                composition.groups.forEach { group ->
                    Section(group.name, group.rule()) {
                        group.options.forEach { option ->
                            val count = options[option.id] ?: 0
                            val full = group.max != null && group.chosenCount(options) >= group.max
                            if (group.allowsQuantity) {
                                QuantityRow(
                                    option = option,
                                    price = option.priceFor(sizeId),
                                    count = count,
                                    canAdd = !option.paused && !full,
                                    onChange = { next -> if (next > 0) options[option.id] = next else options.remove(option.id) },
                                )
                            } else {
                                ChoiceRow(
                                    option = option,
                                    price = option.priceFor(sizeId),
                                    selected = count > 0,
                                    enabled = !option.paused && (count > 0 || !full || group.max == 1),
                                ) {
                                    when {
                                        count > 0 -> options.remove(option.id)
                                        group.max == 1 -> {
                                            group.options.forEach { options.remove(it.id) }
                                            options[option.id] = 1
                                        }
                                        else -> options[option.id] = 1
                                    }
                                }
                            }
                        }
                    }
                }

                OutlinedTextField(
                    value = notes,
                    onValueChange = { notes = it.take(NOTES_MAX) },
                    label = { Text("Observação") },
                    placeholder = { Text("Ex.: sem cebola") },
                    supportingText = { Text("${notes.length}/$NOTES_MAX") },
                    modifier = Modifier.fillMaxWidth(),
                )
            }

            Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                sheet.error?.let {
                    Text(it, color = MaterialTheme.colorScheme.error, fontSize = 14.sp, modifier = Modifier.fillMaxWidth())
                }
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Stepper(quantity, onChange = { quantity = it }, canAdd = quantity < 99, label = "unidade")
                    Button(
                        onClick = {
                            onAdd(
                                composedLine(
                                    productId = product.id,
                                    name = product.name,
                                    quantity = quantity,
                                    notes = notes.trim().ifEmpty { null },
                                    sizeId = sizeId.takeIf { pizza != null },
                                    flavorIds = chosenFlavors,
                                    options = options.toSortedMap().map { (id, count) -> ChosenOption(id, count) },
                                ),
                            )
                        },
                        enabled = ready && !sheet.quoting,
                        shape = RoundedCornerShape(14.dp),
                        modifier = Modifier.weight(1f).height(56.dp),
                    ) {
                        if (sheet.quoting) {
                            CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                        } else {
                            Text("Adicionar", fontSize = 17.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }
        }
    }
}

/** Cada montagem diferente vira uma linha própria no carrinho; a mesma montagem soma na linha existente. */
internal fun composedLine(
    productId: Long,
    name: String,
    quantity: Int,
    notes: String?,
    sizeId: Long?,
    flavorIds: List<Long>,
    options: List<ChosenOption>,
): CartLine = CartLine(
    key = listOf("c$productId", "s$sizeId", "f${flavorIds.joinToString(",")}", "o${options.joinToString(",") { "${it.optionId}x${it.quantity}" }}", "n${notes.orEmpty()}")
        .joinToString("|"),
    productId = productId,
    name = name,
    quantity = quantity,
    unitPrice = "0.00",
    notes = notes,
    sizeId = sizeId,
    flavorIds = flavorIds,
    options = options,
)

private fun OptionGroup.chosenCount(chosen: Map<Long, Int>): Int = options.sumOf { chosen[it.id] ?: 0 }

private fun OptionGroup.rule(): String = when {
    max == null && min == 0 -> "Opcional"
    max == null -> "Mínimo $min"
    min == max -> "Escolha $min"
    min == 0 -> "Opcional, até $max"
    else -> "De $min a $max"
}

private fun MenuOption.priceFor(sizeId: Long?): String = sizeId?.let { pricesBySize[it] } ?: price

@Composable
private fun Section(title: String, rule: String, content: @Composable () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.Bottom) {
            Text(title, fontSize = 17.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            Text(rule, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        content()
    }
}

@Composable
private fun SizeRow(size: PizzaSize, selected: Boolean, onClick: () -> Unit) {
    val detail = listOfNotNull(
        size.slices.takeIf { it > 0 }?.let { "$it fatias" },
        if (size.maxFlavors > 1) "até ${size.maxFlavors} sabores" else "1 sabor",
    ).joinToString(" · ")
    OptionSurface(selected = selected, enabled = true, onClick = onClick) {
        Column(Modifier.weight(1f)) {
            Text(size.name, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
            Text(detail, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun ChoiceRow(option: MenuOption, price: String, selected: Boolean, enabled: Boolean, onClick: () -> Unit) {
    OptionSurface(selected = selected, enabled = enabled, onClick = onClick) {
        OptionText(option, price, Modifier.weight(1f))
    }
}

@Composable
private fun QuantityRow(option: MenuOption, price: String, count: Int, canAdd: Boolean, onChange: (Int) -> Unit) {
    OptionSurface(selected = count > 0, enabled = !option.paused || count > 0, onClick = null) {
        OptionText(option, price, Modifier.weight(1f))
        Stepper(count, onChange = onChange, canAdd = canAdd, label = option.name, minimum = 0)
    }
}

@Composable
private fun OptionText(option: MenuOption, price: String, modifier: Modifier) {
    Column(modifier) {
        Text(option.name, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
        when {
            option.paused -> Text("Indisponível", fontSize = 13.sp, color = MaterialTheme.colorScheme.error)
            (price.toBigDecimalOrNull()?.signum() ?: 0) > 0 ->
                Text("+ ${money(price) ?: price}", fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun OptionSurface(
    selected: Boolean,
    enabled: Boolean,
    onClick: (() -> Unit)?,
    content: @Composable androidx.compose.foundation.layout.RowScope.() -> Unit,
) {
    val border = BorderStroke(if (selected) 2.dp else 1.dp, if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant)
    val row: @Composable () -> Unit = {
        Row(
            modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp).padding(horizontal = 14.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
            content = content,
        )
    }
    val modifier = Modifier.fillMaxWidth().semantics {
        this.selected = selected
        if (onClick != null) role = Role.Checkbox
    }
    val alpha = if (enabled) 1f else 0.45f
    val contentColor = MaterialTheme.colorScheme.onSurface.copy(alpha = alpha)
    if (onClick != null) {
        Surface(onClick = onClick, enabled = enabled, shape = RoundedCornerShape(12.dp), border = border, contentColor = contentColor, modifier = modifier) { row() }
    } else {
        Surface(shape = RoundedCornerShape(12.dp), border = border, contentColor = contentColor, modifier = modifier) { row() }
    }
}

/** Controle − n + com alvos de 44 dp. */
@Composable
fun Stepper(value: Int, onChange: (Int) -> Unit, canAdd: Boolean, label: String, minimum: Int = 1) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        OutlinedIconButton(onClick = { onChange(value - 1) }, enabled = value > minimum, modifier = Modifier.size(44.dp)) {
            Icon(painterResource(R.drawable.ic_minus), contentDescription = "Menos $label", modifier = Modifier.size(20.dp))
        }
        Text(
            value.toString(),
            fontSize = 17.sp,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
            modifier = Modifier.width(36.dp),
        )
        OutlinedIconButton(onClick = { onChange(value + 1) }, enabled = canAdd, modifier = Modifier.size(44.dp)) {
            Icon(painterResource(R.drawable.ic_plus), contentDescription = "Mais $label", modifier = Modifier.size(20.dp))
        }
    }
}
