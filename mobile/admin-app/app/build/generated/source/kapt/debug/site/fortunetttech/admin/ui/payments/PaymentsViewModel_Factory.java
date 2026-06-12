package site.fortunetttech.admin.ui.payments;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.repository.PaymentRepository;

@ScopeMetadata
@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class PaymentsViewModel_Factory implements Factory<PaymentsViewModel> {
  private final Provider<PaymentRepository> repoProvider;

  public PaymentsViewModel_Factory(Provider<PaymentRepository> repoProvider) {
    this.repoProvider = repoProvider;
  }

  @Override
  public PaymentsViewModel get() {
    return newInstance(repoProvider.get());
  }

  public static PaymentsViewModel_Factory create(Provider<PaymentRepository> repoProvider) {
    return new PaymentsViewModel_Factory(repoProvider);
  }

  public static PaymentsViewModel newInstance(PaymentRepository repo) {
    return new PaymentsViewModel(repo);
  }
}
