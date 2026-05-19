package site.fortunetttech.customer.ui.pay;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.customer.data.repository.PayRepository;

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
public final class PayViewModel_Factory implements Factory<PayViewModel> {
  private final Provider<PayRepository> repoProvider;

  public PayViewModel_Factory(Provider<PayRepository> repoProvider) {
    this.repoProvider = repoProvider;
  }

  @Override
  public PayViewModel get() {
    return newInstance(repoProvider.get());
  }

  public static PayViewModel_Factory create(Provider<PayRepository> repoProvider) {
    return new PayViewModel_Factory(repoProvider);
  }

  public static PayViewModel newInstance(PayRepository repo) {
    return new PayViewModel(repo);
  }
}
