<template>
  <Authenticated>
    <form @submit.prevent="reviewing = true">
      <FormMultipartWrapper>
        <div class="text-gray-700 dark:text-gray-100 mb-8">
          <p class="mb-4">{{ __('Combining invoices allows you to join multiple invoices into a single central invoice, while maintaining all the data and student association of the subsidiary invoices. Payments will be distributed among the subsidiary invoices, which is configurable when recording the payment.') }}</p>
          <p class="mb-4">{{ __('') }}</p>
          <h2 class="pt-2 mb-4 font-bold text-lg">{{ __('When do I combine invoices?') }}</h2>
          <p class="mb-4">{{ __("Sometimes it's easier for guardians of multiple children to receive a single invoice rather than a single invoice for each child. If the invoice will be paid by another third party, they may prefer to receive a single invoice summary of all the invoices that they will pay.") }}</p>
        </div>

        <div class="pt-8 mb-6">
          <CardSectionHeader>{{ __('Invoices') }}</CardSectionHeader>
          <HelpText>{{ __('These are the invoices that will be combined. Invoices that are void will not be included.') }}</HelpText>

          <Table class="mt-8">
            <Thead>
              <tr>
                <Th>{{ __('Title') }}</Th>
                <Th>{{ __('Student') }}</Th>
                <Th class="text-right">{{ __('Total due') }}</Th>
                <Th class="text-right">{{ __('Remaining balance') }}</Th>
                <Th></Th>
              </tr>
            </Thead>
            <Tbody>
              <tr v-for="invoice in selection" :key="invoice.uuid">
                <Td>
                  <TableLink :href="`/invoices/${invoice.uuid}`">{{ invoice.title }}</TableLink> <SolidBadge v-if="invoice.is_void" color="red">{{ __('Void') }}</SolidBadge>
                </Td>
                <Td>
                  <TableLink :href="`/students/${invoice.student.id}`">{{ invoice.student.full_name }}</TableLink></Td>
                <Td class="text-right">{{ invoice.amount_due_formatted }}</Td>
                <Td class="text-right">{{ invoice.remaining_balance_formatted }}</Td>
                <Td class="text-right">
                  <BaseButton type="button" @click.prevent="removeInvoice(invoice)">
                    <TrashIcon class="w-4 h-4 text-red-500 dark:text-red-400" />
                  </BaseButton>
                </Td>
              </tr>
            </Tbody>
          </Table>
        </div>

        <div class="pt-8">
          <CardSectionHeader>{{ __('Assigned users') }}<Req /></CardSectionHeader>
          <HelpText>
            {{ __("When you combine invoices, the original users will not be able to view the individual invoices. Only the users selected below will be able to view and interact with the combined invoice.") }}
          </HelpText>

          <div class="mt-4 space-y-1">
            <div
              v-for="user in suggestedUsers"
              :key="user.id"
            >
              <CheckboxWrapper>
                <Checkbox v-model:checked="form.users" :value="user.id" />
                <CheckboxText>
                  {{ user.full_name }} <span class="text-gray-500">({{ user.email }})</span>
                </CheckboxText>
              </CheckboxWrapper>
            </div>
            <Error v-if="form.errors.users">{{ form.errors.users }}</Error>
          </div>

          <div class="mt-4">
            <InputWrap>
              <Label for="user_search">{{ __('Add a user') }}</Label>
              <UserTypeahead v-model="searchedUser" id="user_search" />
              <HelpText>{{ __('Find additional users to which to assign this invoice.') }}</HelpText>
            </InputWrap>
          </div>
        </div>

        <div class="pt-8">
          <InvoiceDetailsForm v-model="form" />
        </div>

        <!-- Payment schedules -->
        <div class="pt-8">
          <InvoicePaymentSchedules
            v-model="form.payment_schedules"
            :form="form"
            :total="total"
          />
        </div>

        <div class="mt-8 p-4 border-t border-gray-400 bg-gray-200 dark:bg-gray-700 dark:border-gray-300 rounded-b-md space-x-3">
          <Button type="button" @click.prevent="reviewing = true">
            {{ __('Review and combine') }}
          </Button>
          <Button type="button" @click.prevent="saveAsDraft" color="white">
            <span v-if="isNew">{{ __('Save as draft') }}</span>
            <span v-else>{{ __('Update draft') }}</span>
          </Button>
        </div>
      </FormMultipartWrapper>
    </form>


    <Modal
      v-if="reviewing"
      @close="reviewing = false"
      @action="combine"
      :action-loading="form.processing"
      size="xl"
    >
      <CombineInvoiceSummary
        :invoice="form"
        :total="total"
        :selection="selection"
      />
    </Modal>
  </Authenticated>
</template>

<script>
import { computed, defineComponent, inject, ref, watch } from 'vue'
import Authenticated from '@/layouts/Authenticated.vue'
import PageProps from '@/mixins/PageProps'
import { useForm } from '@inertiajs/vue3'
import FormMultipartWrapper from '@/components/forms/FormMultipartWrapper.vue'
import CardSectionHeader from '@/components/CardSectionHeader.vue'
import HelpText from '@/components/HelpText.vue'
import Table from '@/components/tables/Table.vue'
import Thead from '@/components/tables/Thead.vue'
import Th from '@/components/tables/Th.vue'
import Tbody from '@/components/tables/Tbody.vue'
import Td from '@/components/tables/Td.vue'
import SolidBadge from '@/components/SolidBadge.vue'
import TableLink from '@/components/tables/TableLink.vue'
import InvoiceDetailsForm from '@/components/forms/invoices/InvoiceDetailsForm.vue'
import Button from '@/components/Button.vue'
import { TrashIcon } from '@heroicons/vue/outline'
import BaseButton from '@/components/BaseButton.vue'
import { router } from '@inertiajs/vue3'
import CheckboxWrapper from '@/components/forms/CheckboxWrapper.vue'
import Checkbox from '@/components/forms/Checkbox.vue'
import CheckboxText from '@/components/forms/CheckboxText.vue'
import Req from '@/components/forms/Req.vue'
import InvoicePaymentSchedules from '@/components/forms/invoices/InvoicePaymentSchedules.vue'
import Modal from '@/components/Modal.vue'
import CombineInvoiceSummary from '@/components/CombineInvoiceSummary.vue'
import isUndefined from 'lodash/isUndefined'
import Error from '@/components/forms/Error.vue'
import InputWrap from '@/components/forms/InputWrap.vue'
import Label from '@/components/forms/Label.vue'
import UserTypeahead from '@/components/forms/UserTypeahead.vue'

export default defineComponent({
  mixins: [PageProps],
  components: {
    UserTypeahead,
    Label,
    InputWrap,
    CombineInvoiceSummary,
    Modal,
    InvoicePaymentSchedules,
    Req,
    CheckboxText,
    Checkbox,
    CheckboxWrapper,
    BaseButton,
    Button,
    InvoiceDetailsForm,
    TableLink,
    SolidBadge,
    Td,
    Tbody,
    Th,
    Thead,
    Table,
    HelpText,
    CardSectionHeader,
    FormMultipartWrapper,
    Authenticated,
    TrashIcon,
    Error,
  },
  props: {
    selection: Array,
    suggestedUsers: Array,
    invoice: {
      type: Object,
      default: () => ({})
    },
    assignedUsers: {
      type: Array,
      default: () => ([])
    },
    endpoint: {
      type: String,
      required: true,
    },
    method: {
      type: String,
      required: true,
    }
  },

  setup (props) {
    const $route = inject('$route')
    const reviewing = ref(false)
    const isNew = computed(() => !props.invoice.uuid)
    const form = useForm({
      draft: !props.invoice.published_at,
      users: [...props.assignedUsers],
      title: props.invoice.title || null,
      description: props.invoice.description || null,
      term_id: props.invoice.term_id || null,
      invoice_date: props.invoice.invoice_date ? new Date(props.invoice.invoice_date) : new Date(),
      available_at: props.invoice.available_at ? new Date(props.invoice.available_at) : null,
      due_at: props.invoice.due_at ? new Date(props.invoice.due_at) : null,
      notify: isUndefined(props.invoice.notify) ? true : props.invoice.notify,
      payment_schedules: props.invoice.payment_schedules || [],
    })
    const removeInvoice = invoice => {
      const route = props.invoice.uuid
        ? `/child/${invoice.uuid}`
        : $route('invoice-selection.update', invoice.uuid)

      router.delete(route, {
        preserveScroll: true,
      })
    }

    const combine = (asDraft = false) => {
      form.draft = asDraft
      form[props.method](props.endpoint)
    }
    const saveAsDraft = () => {
      combine(true)
    }

    const total = computed(
      () => props.selection.reduce((total, invoice) => total + invoice.amount_due, 0)
    )

    const searchedUser = ref()
    watch(searchedUser, (value) => {
      if (value) {
        if (!props.suggestedUsers.some(u => u.id === value.id)) {
          props.suggestedUsers.push(value)
          form.users.push(value.id)
        }
        searchedUser.value = null
      }
    })

    return {
      form,
      combine,
      removeInvoice,
      total,
      reviewing,
      saveAsDraft,
      isNew,
      searchedUser,
    }
  }
})
</script>
